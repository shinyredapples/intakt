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

    // Quote, bracket and separator vary because headers appear in logs, JSON, shell and dumps; spaces are possessive so none is redacted for a value.
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
        $text = $this->redactHeaders($text);
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

    // Every spelling is allowed because JSON may write a character as itself, a short escape or a \u escape, with more backslashes per encoding.
    private function jsonSpellings(string $char): string
    {
        // A plain ASCII character matches only as itself, so a long credential's pattern stays well under PCRE's compiled size.
        if (!$this->jsonMayEscape($char)) {
            return preg_quote($char, '~');
        }

        $spellings = [in_array($char, ['"', '\\', '/'], true) ? '\\\\*'.preg_quote($char, '~') : preg_quote($char, '~')];
        if (isset(self::JSON_SHORT_ESCAPES[$char])) {
            $spellings[] = '\\\\+'.self::JSON_SHORT_ESCAPES[$char];
        }
        if (mb_check_encoding($char, 'UTF-8')) {
            $spellings[] = $this->unicodeEscape($char);
        }

        return '(?:'.implode('|', $spellings).')';
    }

    // Only control characters, non-ASCII and the JSON_HEX_* characters get escape spellings, because json_encode escapes nothing else and each spelling grows the pattern.
    private function jsonMayEscape(string $char): bool
    {
        return strlen($char) > 1 || ord($char[0]) < 0x20 || str_contains('"\\/<>&\'', $char);
    }

    private function unicodeEscape(string $char): string
    {
        // One escape per UTF-16 unit, because JSON writes a character outside the basic plane as a surrogate pair.
        $units = str_split(bin2hex(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')), 4);

        return implode('', array_map(static fn (string $unit): string => '\\\\+u(?i:'.$unit.')', $units));
    }

    private function redactHeaders(string $text): string
    {
        [$text, $listMarkers] = $this->redactHeaderLists($text);

        return $this->redactHeaderValues($text, $listMarkers);
    }

    /**
     * @return array{string, list<int>}
     */
    private function redactHeaderLists(string $text): array
    {
        // The print_r form is told apart because it alone breaks the line before its parenthesis; a held credential's marker opens no list, so the value after it is still redacted.
        $pattern = '~'.self::HEADER_HEAD.'(?<open>(?<printR>array\R[ \t]*\()|array\s*\(|(?!'.preg_quote(self::MARKER, '~').')\[)~i';

        $result = '';
        $listMarkers = [];
        $offset = 0;
        $from = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $from)) {
            $matchStart = $match[0][1];
            $afterOpen = $matchStart + strlen($match[0][0]);
            $from = $afterOpen;
            $end = match (true) {
                // print_r does not quote values, so no line marks the end of its list for certain and the rest of the text goes.
                -1 !== ($match['printR'][1] ?? -1) => strlen($text),
                str_ends_with($match['open'][0], '(') => $this->endOfQuotedList($text, $afterOpen, '()'),
                default => $this->endOfQuotedList($text, $afterOpen, '[]'),
            };
            // A bracket that opens no list is left to the value pass, which redacts the value to the end of its line or quote.
            if ($end < strlen($text) && !$this->isListShaped($text, $afterOpen, $end)) {
                continue;
            }

            // A missing end takes the rest of the text because stopping early would leak.
            $result .= substr($text, $offset, $matchStart - $offset).substr($match[0][0], 0, -strlen($match['open'][0]));
            $listMarkers[] = strlen($result);
            $result .= self::MARKER;
            $offset = $from = $end;
        }

        return [$result.substr($text, $offset), $listMarkers];
    }

    // A plain value may start with a bracket too (`[Bearer] token`), so only a list whose first element is quoted, keyed, nested or missing, and that a separator or the line's end follows, keeps the text after it.
    private function isListShaped(string $text, int $afterOpen, int $end): bool
    {
        $listStart = '~\G\s*+(?:["\'\[\])]|\d+\s*+=>|array\s*+\()~i';
        $listEnd = '~\G[ \t]*+(?:[,\]})]|\R|\z)~';

        return 1 === preg_match($listStart, $text, offset: $afterOpen) && 1 === preg_match($listEnd, $text, offset: $end);
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

    /**
     * @param list<int> $listMarkers
     */
    private function redactHeaderValues(string $text, array $listMarkers): string
    {
        // A quoted value takes a backslash run and a quote as part of itself, so an escaped quote does not end it, and the other quote character is plain text in it.
        $quoted = '(?<open>\\\\*["\'])(?:(?!\k<open>)(?:\\\\+["\']|[^\\\\]|\\\\))*+(?:\k<open>|\z)';
        // Quoted Digest parameters stay inside so that the value ends at the line or at the closing quote of a shell word.
        $unquoted = '(?:[^\r\n"\']|"[^"\r\n]*")+';
        $pattern = '~(?<head>'.self::HEADER_HEAD.')(?:'.$quoted.'|'.$unquoted.')~i';

        $result = '';
        $offset = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $valueStart = $match['head'][1] + strlen($match['head'][0]);
            $result .= substr($text, $offset, $valueStart - $offset);
            // Only a list's own marker is skipped, so the text after the list stays, while a value that merely holds a list opening is redacted whole.
            if (in_array($valueStart, $listMarkers, true)) {
                $result .= self::MARKER;
                $offset = $valueStart + strlen(self::MARKER);

                continue;
            }

            $open = $match['open'][0] ?? '';
            $result .= $open.self::MARKER.$open;
            $offset = $match[0][1] + strlen($match[0][0]);
        }

        // A failed match would leave the rest unredacted, so nothing of the text goes out.
        return PREG_NO_ERROR === preg_last_error() ? $result.substr($text, $offset) : self::MARKER;
    }

    private function redactUserinfo(string $text): string
    {
        // Backslashes are allowed before each slash because JSON writes the separator as :\/\/, once more per encoding.
        return preg_replace('~(?<scheme>\b[a-z][a-z0-9+.-]*:(?:\\\\*/){2})[^\s/?#"\'<>]+@~i', '$1'.self::MARKER.'@', $text) ?? self::MARKER;
    }

    private function redactSecretShapes(string $text): string
    {
        return preg_replace(self::SECRET_SHAPES, self::MARKER, $text) ?? self::MARKER;
    }
}
