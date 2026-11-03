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
    private const string HEADER_HEAD = '(?<![\w-])(?:'.self::HEADER_NAMES.')[\\\\"\'\]]*\s*(?:=>|:|=)\s*';

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
        $parts = array_map(
            // JSON escapes these three with a backslash, so every further encoding adds more of them.
            static fn (string $char): string => in_array($char, ['"', '\\', '/'], true)
                ? '\\\\*'.preg_quote($char, '~')
                : preg_quote($char, '~'),
            str_split($credential),
        );

        return '~'.implode('', $parts).'~';
    }

    private function redactHeaderLists(string $text): string
    {
        $pattern = '~'.self::HEADER_HEAD.'(?<open>array\s*\(|\[)~i';

        $result = '';
        $offset = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $match[0][1];
            $afterOpen = $matchStart + strlen($match[0][0]);
            $end = str_ends_with($match['open'][0], '(')
                ? $this->endOfParenthesisList($text, $afterOpen)
                : $this->endOfBracketList($text, $afterOpen);

            // A missing end takes the rest of the text because stopping early would leak.
            $result .= substr($text, $offset, $matchStart - $offset).substr($match[0][0], 0, -strlen($match['open'][0])).self::MARKER;
            $offset = $end;
        }

        return $result.substr($text, $offset);
    }

    private function endOfParenthesisList(string $text, int $from): int
    {
        // The closing parenthesis sits on a line of its own because print_r and var_export both print it so.
        if (1 === preg_match('~\R\s*\)~', $text, $close, PREG_OFFSET_CAPTURE, $from)) {
            return $close[0][1] + strlen($close[0][0]);
        }

        return strlen($text);
    }

    private function endOfBracketList(string $text, int $from): int
    {
        // Quoted strings are skipped whole because a bracket inside a header value must not close the list.
        $tokens = '~\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|[\[\]]~s';
        preg_match_all($tokens, $text, $matches, PREG_OFFSET_CAPTURE, $from);

        $depth = 1;
        foreach ($matches[0] as [$token, $position]) {
            $depth += '[' === $token ? 1 : 0;
            $depth -= ']' === $token ? 1 : 0;
            if (0 === $depth) {
                return $position + 1;
            }
        }

        return strlen($text);
    }

    private function redactHeaderValues(string $text): string
    {
        // A quoted value takes a backslash run and a quote as part of itself, so an escaped quote does not end it.
        $quoted = '(?<open>\\\\*["\'])(?:(?!\k<open>)(?:\\\\+["\']|[^"\'\\\\]|\\\\))*+(?:\k<open>|\z)';
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
        return preg_replace('~(?<scheme>\b[a-z][a-z0-9+.-]*://)[^\s/?#"\'<>]+@~i', '$1'.self::MARKER.'@', $text) ?? self::MARKER;
    }

    private function redactSecretShapes(string $text): string
    {
        return preg_replace(self::SECRET_SHAPES, self::MARKER, $text) ?? self::MARKER;
    }
}
