<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use NoDiscard;
use SensitiveParameter;

// One home for what must never leave Intakt in text: posted review text and log records both pass through it.
/**
 * @phpstan-type Span array{int, int, string}
 */
final readonly class Redaction
{
    public const string MARKER = '[redacted]';

    // A redacted part is masked with bytes no pattern takes as a header, scheme, quote or word, so the next pass sees every other byte where it was.
    private const string MASK = "\0";

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

    // Every pass finds its parts in the original text and all parts are replaced at the end, so a held credential that is also a header name or a scheme still lets its header or URL be found, and overlapping parts become one marker.
    #[NoDiscard]
    public function redact(string $text, #[SensitiveParameter] string ...$heldCredentials): string
    {
        $spans = $this->heldCredentialSpans($text, array_values($heldCredentials));
        $masked = $text;
        foreach ([$this->headerSpans(...), $this->userinfoSpans(...), $this->secretShapeSpans(...)] as $pass) {
            $found = $pass($masked);
            // A failed match would leave the rest unredacted, so nothing of the text goes out.
            if (null === $spans || null === $found) {
                return self::MARKER;
            }

            $masked = $this->masked($masked, $found);
            $spans = [...$spans, ...$found];
        }

        return $this->replaced($text, $spans);
    }

    /**
     * @param list<string> $credentials
     *
     * @return list<Span>|null
     */
    private function heldCredentialSpans(string $text, array $credentials): ?array
    {
        $spans = [];
        foreach ($this->withTrimmedForms($credentials) as $credential) {
            if (false === preg_match_all($this->heldCredentialPattern($credential), $text, $matches, PREG_OFFSET_CAPTURE)) {
                return null;
            }

            foreach ($matches[0] as [$found, $at]) {
                $spans[] = [$at, $at + strlen($found), self::MARKER];
            }
        }

        return $spans;
    }

    // A value is often stored with a trailing newline (a secrets file, a heredoc) but quoted without it, so its trimmed form is held too.
    /**
     * @param list<string> $credentials
     *
     * @return list<string>
     */
    private function withTrimmedForms(array $credentials): array
    {
        $forms = array_filter([...$credentials, ...array_map(trim(...), $credentials)], static fn (string $form): bool => '' !== $form);

        return array_values(array_unique($forms));
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

    /**
     * @return list<Span>|null
     */
    private function headerSpans(string $text): ?array
    {
        [$lists, $keptTails] = $this->headerListSpans($text);
        $values = $this->headerValueSpans($this->masked($text, $lists), $keptTails);

        return null === $values ? null : [...$lists, ...$values];
    }

    /**
     * @return array{list<Span>, array<int, int>}
     */
    private function headerListSpans(string $text): array
    {
        // The print_r form is told apart because it alone breaks the line before its parenthesis; a marker already in the text opens no list, so the value after it is still redacted.
        $pattern = '~'.self::HEADER_HEAD.'(?<open>(?<printR>array\R[ \t]*\()|array\s*\(|(?!'.preg_quote(self::MARKER, '~').')\[)~i';

        $before = '';
        $spans = [];
        $keptTails = [];
        $offset = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $match[0][1];
            $afterOpen = $matchStart + strlen($match[0][0]);
            $listStart = $afterOpen - strlen($match['open'][0]);
            $end = match (true) {
                // print_r does not quote values, so no line marks the end of its list for certain and the rest of the text goes.
                -1 !== ($match['printR'][1] ?? -1) => strlen($text),
                str_ends_with($match['open'][0], '(') => $this->endOfQuotedList($text, $afterOpen, '()'),
                default => $this->endOfQuotedList($text, $afterOpen, '[]'),
            };

            // A missing end takes the rest of the text because stopping early would leak.
            $before .= substr($text, $offset, $listStart - $offset);
            // A list whose tail is not kept is redacted by the value pass with the rest of its value, because a plain header value may start with a bracket (`[Bearer] token`) and its tail is secret too.
            if ($this->isValueOfQuotedKey($text, $matchStart)
                && !$this->followsUnquotedHeaderOnItsLine($before)
                && $this->isListShaped($text, $afterOpen, $end)) {
                $keptTails[$listStart] = $end;
            }
            $spans[] = [$listStart, $end, self::MARKER];
            $before .= str_repeat(self::MASK, $end - $listStart);
            $offset = $end;
        }

        return [$spans, $keptTails];
    }

    // Only structured data quotes its keys (JSON, var_export), so only there can a closing bracket be trusted to end the value.
    private function isValueOfQuotedKey(string $text, int $nameStart): bool
    {
        $quote = 0 < $nameStart ? $text[$nameStart - 1] : '';
        if ('"' !== $quote && "'" !== $quote) {
            return false;
        }

        return 1 === preg_match('~\G(?:'.self::HEADER_NAMES.')\\\\*'.$quote.'~i', $text, offset: $nameStart);
    }

    // A quoted key after an unquoted header on the same line sits inside that header's value, so the value pass must redact it to the end of the line.
    private function followsUnquotedHeaderOnItsLine(string $before): bool
    {
        $lineStart = (int) strrpos("\n".strtr($before, "\r", "\n"), "\n");
        preg_match_all('~'.self::HEADER_HEAD.'~i', $before, $heads, PREG_OFFSET_CAPTURE, $lineStart);

        return array_any($heads[0], fn (array $head): bool => !$this->isValueOfQuotedKey($before, $head[1]));
    }

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
     * @param array<int, int> $keptTails
     *
     * @return list<Span>|null
     */
    private function headerValueSpans(string $text, array $keptTails): ?array
    {
        // A quoted value takes a backslash run and a quote as part of itself, so an escaped quote does not end it, and the other quote character is plain text in it.
        $quoted = '(?<open>\\\\*["\'])(?:(?!\k<open>)(?:\\\\+["\']|[^\\\\]|\\\\))*+(?:(?<close>\k<open>)|\z)';
        // Quoted Digest parameters stay inside so that the value ends at the line or at the closing quote of a shell word.
        $unquoted = '(?:[^\r\n"\']|"[^"\r\n]*")+';
        $pattern = '~(?<head>'.self::HEADER_HEAD.')(?:'.$quoted.'|'.$unquoted.')~i';

        $spans = [];
        $offset = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $valueStart = $match['head'][1] + strlen($match['head'][0]);
            // Only a list's own part is skipped, so the text after the list stays, while a value that merely holds a list opening is redacted whole.
            if (isset($keptTails[$valueStart])) {
                $offset = $keptTails[$valueStart];

                continue;
            }

            $open = $match['open'][0] ?? '';
            $valueEnd = $match[0][1] + strlen($match[0][0]);
            // A quoted value cut off by the end of the text is closed after its marker, as it would have been.
            $closed = '' === $open || -1 !== ($match['close'][1] ?? -1);
            $spans[] = [$valueStart + strlen($open), $closed ? $valueEnd - strlen($open) : $valueEnd, $closed ? self::MARKER : self::MARKER.$open];
            $offset = $valueEnd;
        }

        return PREG_NO_ERROR === preg_last_error() ? $spans : null;
    }

    /**
     * @return list<Span>|null
     */
    private function userinfoSpans(string $text): ?array
    {
        // Backslashes are allowed before each slash because JSON writes the separator as :\/\/, once more per encoding.
        if (false === preg_match_all('~(?<scheme>\b[a-z][a-z0-9+.-]*:(?:\\\\*/){2})[^\s/?#"\'<>]+@~i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return array_map(
            static fn (array $scheme, array $whole): array => [$scheme[1] + strlen($scheme[0]), $whole[1] + strlen($whole[0]) - 1, self::MARKER],
            $matches['scheme'],
            $matches[0],
        );
    }

    /**
     * @return list<Span>|null
     */
    private function secretShapeSpans(string $text): ?array
    {
        if (false === preg_match_all(self::SECRET_SHAPES, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return array_map(static fn (array $shape): array => [$shape[1], $shape[1] + strlen($shape[0]), self::MARKER], $matches[0]);
    }

    /**
     * @param list<Span> $spans
     */
    private function masked(string $text, array $spans): string
    {
        foreach ($spans as [$start, $end]) {
            $text = substr_replace($text, str_repeat(self::MASK, $end - $start), $start, $end - $start);
        }

        return $text;
    }

    /**
     * @param list<Span> $spans
     */
    private function replaced(string $text, array $spans): string
    {
        // Overlapping parts, and a credential found in full and trimmed at one place, merge into one marker; parts that only touch keep one each.
        usort($spans, static fn (array $a, array $b): int => [$a[0], $b[1]] <=> [$b[0], $a[1]]);

        $result = '';
        $offset = 0;
        $current = null;
        foreach ($spans as $span) {
            if (null !== $current && ($span[0] < $current[1] || $span[0] === $current[0])) {
                $current = $span[1] > $current[1] ? [$current[0], $span[1], $span[2]] : $current;

                continue;
            }

            if (null !== $current) {
                $result .= substr($text, $offset, $current[0] - $offset).$current[2];
                $offset = $current[1];
            }
            $current = $span;
        }

        if (null !== $current) {
            $result .= substr($text, $offset, $current[0] - $offset).$current[2];
            $offset = $current[1];
        }

        return $result.substr($text, $offset);
    }
}
