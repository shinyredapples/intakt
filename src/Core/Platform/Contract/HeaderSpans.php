<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

final readonly class HeaderSpans
{
    private const string HEADER_NAMES = 'proxy-authorization|authorization|set-cookie|cookie|x-api-key|x-auth-token';

    // Quote, bracket and separator vary because headers appear in logs, JSON, shell and dumps; spaces are possessive so none is redacted for a value.
    private const string HEADER_HEAD = '(?<![\w-])(?:'.self::HEADER_NAMES.')[\\\\"\'\]]*\s*+(?:=>|:|=)\s*+';

    public function lists(string $text): ListedHeaders
    {
        // The print_r form is told apart because it alone breaks the line before its parenthesis; a marker already in the text opens no list, so the value after it is still redacted.
        $pattern = '~'.self::HEADER_HEAD.'(?<open>(?<printR>array\R[ \t]*\()|array\s*\(|(?!'.preg_quote(Redaction::MARKER, '~').')\[)~i';

        $before = '';
        $spans = new RedactedSpans();
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
            $spans->add($listStart, $end);
            $before .= str_repeat(Redaction::MASK, $end - $listStart);
            $offset = $end;
        }

        return new ListedHeaders($spans, $keptTails);
    }

    public function values(string $text, ListedHeaders $listed): ?RedactedSpans
    {
        // A quoted value takes a backslash run and a quote as part of itself, so an escaped quote does not end it, and the other quote character is plain text in it.
        $quoted = '(?<open>\\\\*["\'])(?:(?!\k<open>)(?:\\\\+["\']|[^\\\\]|\\\\))*+(?:(?<close>\k<open>)|\z)';
        // Quoted Digest parameters stay inside so that the value ends at the line or at the closing quote of a shell word.
        $unquoted = '(?:[^\r\n"\']|"[^"\r\n]*")+';
        $pattern = '~(?<head>'.self::HEADER_HEAD.')(?:'.$quoted.'|'.$unquoted.')~i';

        $spans = new RedactedSpans();
        $offset = 0;
        while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $valueStart = $match['head'][1] + strlen($match['head'][0]);
            // Only a list's own part is skipped, so the text after the list stays, while a value that merely holds a list opening is redacted whole.
            $keptTail = $listed->tailKeptFrom($valueStart);
            if (null !== $keptTail) {
                $offset = $keptTail;

                continue;
            }

            $this->addValue($spans, $match, $valueStart);
            $offset = $match[0][1] + strlen($match[0][0]);
        }

        return PREG_NO_ERROR === preg_last_error() ? $spans : null;
    }

    /**
     * @param array<array-key, array{string, int}> $match
     */
    private function addValue(RedactedSpans $spans, array $match, int $valueStart): void
    {
        $open = $match['open'][0] ?? '';
        $valueEnd = $match[0][1] + strlen($match[0][0]);
        if ('' === $open || -1 !== ($match['close'][1] ?? -1)) {
            $spans->add($valueStart + strlen($open), $valueEnd - strlen($open));

            return;
        }

        // A quoted value cut off by the end of the text is closed after its marker, as it would have been.
        $spans->add($valueStart + strlen($open), $valueEnd, Redaction::MARKER.$open);
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
}
