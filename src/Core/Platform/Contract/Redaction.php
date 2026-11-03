<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use NoDiscard;
use SensitiveParameter;

// One home for what must never leave Intakt in text: posted review text and log records both pass through it.
final readonly class Redaction
{
    public const string MARKER = '[redacted]';

    private const string HEADER_NAMES = 'proxy-authorization|authorization|set-cookie|cookie|x-api-key|x-auth-token';

    // The closing quote or bracket and the separator vary because a header appears in log lines, JSON, shell flags and dumps.
    // Possessive spaces, so that a value that fails to match cannot hand back a space to be redacted in its place.
    private const string HEADER_HEAD = '(?<![\w-])(?:'.self::HEADER_NAMES.')[\\\\"\'\]]*\s*+(?:=>|:|=)\s*+';

    private const array JSON_SHORT_ESCAPES = ["\x08" => 'b', "\f" => 'f', "\n" => 'n', "\r" => 'r', "\t" => 't'];

    private const string SECRET_SHAPES = '~'
        .'-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)'
        .'|\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})'
        .'|\bsk-[A-Za-z0-9_-]{20,}'
        .'|\bAKIA[0-9A-Z]{16}\b'
        .'|\bxox[abprs]-[A-Za-z0-9-]{10,}'
        .'|\beyJ[\w-]{8,}\.eyJ[\w-]{8,}\.[\w-]+'
        .'|\bBearer\s+[A-Za-z0-9._\\~+/=-]{8,}'
        .'~s';

    #[NoDiscard]
    public function redact(string $text, #[SensitiveParameter] string ...$heldCredentials): string
    {
        $text = $this->redactHeldCredentials($text, array_values($heldCredentials));
        $text = $this->redactHeaderLists($text);
        $text = $this->redactHeaderValues($text);
        $text = $this->redactUserinfo($text);

        return $this->redactSecretShapes($text);
    }

    /**
     * @param list<string> $credentials
     */
    private function redactHeldCredentials(string $text, array $credentials): string
    {
        // Longest first, so a credential that contains another is redacted whole.
        usort($credentials, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($credentials as $credential) {
            if ('' === $credential) {
                continue;
            }

            $text = preg_replace($this->heldCredentialPattern($credential), self::MARKER, $text) ?? self::MARKER;
        }

        return $text;
    }

    private function heldCredentialPattern(string $credential): string
    {
        $chars = mb_str_split($credential, 1, 'UTF-8');

        return '~'.implode('', array_map($this->jsonSpellings(...), $chars)).'~';
    }

    // JSON writes a character as itself, a short escape or a \u escape, and every further encoding adds backslashes before it.
    private function jsonSpellings(string $char): string
    {
        $spellings = [in_array($char, ['"', '\\', '/'], true) ? '\\\\*'.preg_quote($char, '~') : preg_quote($char, '~')];
        if (isset(self::JSON_SHORT_ESCAPES[$char])) {
            $spellings[] = '\\\\+'.self::JSON_SHORT_ESCAPES[$char];
        }
        if (mb_check_encoding($char, 'UTF-8')) {
            $spellings[] = $this->unicodeEscape($char);
        }

        return '(?:'.implode('|', $spellings).')';
    }

    private function unicodeEscape(string $char): string
    {
        // A character outside the basic plane is written as two \u escapes, one per UTF-16 unit.
        $units = str_split(bin2hex(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')), 4);

        return implode('', array_map(static fn (string $unit): string => '\\\\+u(?i:'.$unit.')', $units));
    }

    private function redactHeaderLists(string $text): string
    {
        // print_r breaks the line between Array and its parenthesis; var_export writes "array (" on one line.
        $pattern = '~'.self::HEADER_HEAD.'(?<open>array\R(?<indent>[ \t]*)\(|array\s*\(|\[)~i';

        $result = '';
        $offset = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $match[0][1];
            $afterOpen = $matchStart + strlen($match[0][0]);
            $end = match (true) {
                -1 !== ($match['indent'][1] ?? -1) => $this->endOfPrintRList($text, $afterOpen, $match['indent'][0]),
                str_ends_with($match['open'][0], '(') => $this->endOfQuotedList($text, $afterOpen, '()'),
                default => $this->endOfQuotedList($text, $afterOpen, '[]'),
            };

            // A missing end takes the rest of the text because stopping early would leak.
            $result .= substr($text, $offset, $matchStart - $offset).substr($match[0][0], 0, -strlen($match['open'][0])).self::MARKER;
            $offset = $end;
        }

        return $result.substr($text, $offset);
    }

    private function endOfPrintRList(string $text, int $from, string $indent): int
    {
        // print_r does not quote values, so only the indentation of the opening parenthesis tells its own closing one apart.
        if (1 === preg_match('~\R'.preg_quote($indent, '~').'\)(?=\R|\z)~', $text, $close, PREG_OFFSET_CAPTURE, $from)) {
            return $close[0][1] + strlen($close[0][0]);
        }

        return strlen($text);
    }

    private function endOfQuotedList(string $text, int $from, string $brackets): int
    {
        [$open, $close] = str_split($brackets);
        // Quoted strings are skipped whole because a bracket inside a header value must not close the list.
        $tokens = '~\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|['.preg_quote($brackets, '~').']~s';
        preg_match_all($tokens, $text, $matches, PREG_OFFSET_CAPTURE, $from);

        $depth = 1;
        foreach ($matches[0] as [$token, $position]) {
            $depth += $open === $token ? 1 : 0;
            $depth -= $close === $token ? 1 : 0;
            if (0 === $depth) {
                return $position + 1;
            }
        }

        return strlen($text);
    }

    private function redactHeaderValues(string $text): string
    {
        // A quoted value takes a backslash run and a quote as part of itself, so an escaped quote does not end it, and the other quote character is plain text in it.
        $quoted = '(?<open>\\\\*["\'])(?:(?!\k<open>)(?:\\\\+["\']|[^\\\\]|\\\\))*+(?:\k<open>|\z)';
        // Quoted Digest parameters stay inside so that the value ends at the line or at the closing quote of a shell word.
        $unquoted = '(?:[^\r\n"\']|"[^"\r\n]*")+';

        return preg_replace_callback(
            '~(?<head>'.self::HEADER_HEAD.')(?:'.$quoted.'|'.$unquoted.')~i',
            static fn (array $match): string => $match['head'].($match['open'] ?? '').self::MARKER.($match['open'] ?? ''),
            $text,
        ) ?? self::MARKER;
    }

    private function redactUserinfo(string $text): string
    {
        // JSON writes each slash of the separator as \/, once more per encoding.
        return preg_replace('~(?<scheme>\b[a-z][a-z0-9+.-]*:(?:\\\\*/){2})[^\s/?#"\'<>]+@~i', '$1'.self::MARKER.'@', $text) ?? self::MARKER;
    }

    private function redactSecretShapes(string $text): string
    {
        return preg_replace(self::SECRET_SHAPES, self::MARKER, $text) ?? self::MARKER;
    }
}
