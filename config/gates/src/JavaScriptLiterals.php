<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class JavaScriptLiterals
{
    public const string WHITESPACE = " \t\n\r\v\f";
    public const string LINE_SEPARATOR = "\u{2028}";
    public const string PARAGRAPH_SEPARATOR = "\u{2029}";
    private const string SEPARATOR_LEAD = "\xE2";
    private const string NO_BREAK_SPACE = "\u{00A0}";
    private const string BYTE_ORDER_MARK = "\u{FEFF}";
    private const array THREE_BYTE_SPACES = [
        self::LINE_SEPARATOR, self::PARAGRAPH_SEPARATOR, "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}",
        "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{202F}", "\u{205F}", "\u{3000}", "\u{FEFF}",
    ];
    private const string SPACE_LEADS = "\xC2\xE1\xE2\xE3\xEF";
    private const string WORD_ASCII = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$\\';
    private const string DECIMAL_DIGITS = '0123456789_';
    private const string RADIX_DIGITS = '0123456789abcdefABCDEF_';
    private const array RADIX_PREFIXES = ['x', 'X', 'o', 'O', 'b', 'B'];
    private const array EXPONENTS = ['e', 'E'];
    private const string BIGINT_SUFFIX = 'n';

    public int $length;
    private string $wordBytes;

    public function __construct(
        public string $source,
    ) {
        $this->length = strlen($source);
        // Because any byte of a multibyte character may be part of an identifier, except the lead bytes that Unicode spaces and line ends start with, which are checked on their own.
        $this->wordBytes = self::WORD_ASCII.implode('', array_map(chr(...), array_diff(range(0x80, 0xFF), array_map(ord(...), str_split(self::SPACE_LEADS)))));
    }

    // Because JavaScript also separates tokens with the Unicode spaces and line ends, such as a no-break space, they are not part of an identifier.
    public function multibyteSpaceLengthAt(int $offset): int
    {
        if (self::NO_BREAK_SPACE === substr($this->source, $offset, 2)) {
            return 2;
        }

        return in_array(substr($this->source, $offset, 3), self::THREE_BYTE_SPACES, true) ? 3 : 0;
    }

    public function separatorAt(int $offset): bool
    {
        return in_array(substr($this->source, $offset, 3), [self::LINE_SEPARATOR, self::PARAGRAPH_SEPARATOR], true);
    }

    public function lineEndsAt(int $offset): bool
    {
        $char = $this->source[$offset] ?? '';

        return "\n" === $char || "\r" === $char || $this->separatorAt($offset);
    }

    public function wordStartsAt(int $offset): bool
    {
        $char = $this->source[$offset];

        return str_contains($this->wordBytes, $char) || $this->wordContinuesAt($offset);
    }

    public function numberStartsAt(int $offset): bool
    {
        return 1 === preg_match('/\G\.?[0-9]/', $this->source, offset: $offset);
    }

    public function afterWord(int $offset): int
    {
        $end = $offset + strspn($this->source, $this->wordBytes, $offset);

        while (0 < $continuation = $this->wordContinuationLengthAt($offset, $end)) {
            $end += $continuation + strspn($this->source, $this->wordBytes, $end + $continuation);
        }

        return $end;
    }

    public function functionFollows(int $offset): bool
    {
        return 1 === preg_match('/\Gfunction(?![\w$\\\\])/', $this->source, offset: $this->afterSpaces($offset));
    }

    // Because `a?.5:b` is a conditional with a decimal, `?.` chains only when no digit follows it.
    public function optionalChainingAt(int $offset): bool
    {
        return 1 === preg_match('/\G\?\.(?![0-9])/', $this->source, offset: $offset);
    }

    public function colonFollows(int $offset): bool
    {
        return ':' === ($this->source[$this->afterSpaces($offset)] ?? '');
    }

    // Because a `#!` line is an interpreter directive only at the very start of the source (a byte order mark may precede it), it is skipped there and is no comment.
    public function afterHashbang(): int
    {
        return 1 === preg_match('/\A(?:'.self::BYTE_ORDER_MARK.')?#!/', $this->source) ? $this->lineCommentEnd(0) : 0;
    }

    public function afterNumber(int $offset): int
    {
        if ('0' === $this->source[$offset] && in_array($this->source[$offset + 1] ?? '', self::RADIX_PREFIXES, true)) {
            return $this->afterBigIntSuffix($offset + 2 + strspn($this->source, self::RADIX_DIGITS, $offset + 2));
        }

        $end = $offset + strspn($this->source, self::DECIMAL_DIGITS, $offset);

        // Because a decimal point may end a number, as in `4.`, the digits after it are optional.
        if ('.' === ($this->source[$end] ?? '')) {
            $end += 1 + strspn($this->source, self::DECIMAL_DIGITS, $end + 1);
        }

        return $this->afterBigIntSuffix($end + $this->exponentLength($end));
    }

    // Because an unescaped line feed or carriage return cannot be part of a string, an unclosed string ends there; U+2028 and U+2029 may.
    public function afterString(int $offset, string $quote): int
    {
        $stops = $quote."\\\n\r";

        while (($offset += strcspn($this->source, $stops, $offset)) < $this->length && '\\' === $this->source[$offset]) {
            $offset = $this->afterEscape($offset);
        }

        return $quote === ($this->source[$offset] ?? '') ? $offset + 1 : $offset;
    }

    // Because a slash inside a character class does not end the pattern, and an unclosed pattern ends at its line end.
    public function afterRegex(int $offset): int
    {
        while (($offset += strcspn($this->source, "/\\[\n\r".self::SEPARATOR_LEAD, $offset)) < $this->length) {
            if ('/' === $this->source[$offset]) {
                return $offset + 1 + strspn($this->source, $this->wordBytes, $offset + 1);
            }

            if ($this->lineEndsAt($offset)) {
                return $offset;
            }

            $offset = match ($this->source[$offset]) {
                '\\' => $this->afterPatternEscape($offset),
                '[' => $this->afterCharacterClass($offset + 1),
                default => $offset + 1,
            };
        }

        return $this->length;
    }

    public function lineCommentEnd(int $offset): int
    {
        $stops = "\n\r".self::SEPARATOR_LEAD;
        $end = $offset + strcspn($this->source, $stops, $offset);

        while ($end < $this->length && !$this->lineEndsAt($end)) {
            $end += 1 + strcspn($this->source, $stops, $end + 1);
        }

        return $end;
    }

    public function blockCommentEnd(int $offset): int
    {
        $close = strpos($this->source, '*/', $offset + 2);

        return false === $close ? $this->length : $close + 2;
    }

    public function afterEscape(int $offset): int
    {
        // Because a backslash before CR LF continues a string on the next line, both line-end bytes are escaped.
        $length = "\r\n" === substr($this->source, $offset + 1, 2) ? 3 : 2;

        return min($offset + $length, $this->length);
    }

    // Because a pattern over a long run of spaces can exhaust the pattern engine and then read none of them, the run is read with plain string functions.
    private function afterSpaces(int $offset): int
    {
        do {
            $offset += strspn($this->source, self::WHITESPACE, $offset);
            $space = $this->multibyteSpaceLengthAt($offset);
            $offset += $space;
        } while (0 < $space);

        return $offset;
    }

    // Because a braced escape such as `\u{61}` is part of an identifier, its braces do not end the word.
    private function wordContinuationLengthAt(int $start, int $offset): int
    {
        if ($this->wordContinuesAt($offset)) {
            return 1;
        }

        $escape = [];

        return $offset - 2 >= $start && 1 === preg_match('/\G\\\\u(\{[0-9a-fA-F]+\})/', $this->source, $escape, offset: $offset - 2) ? strlen($escape[1]) : 0;
    }

    private function wordContinuesAt(int $offset): bool
    {
        $char = $this->source[$offset] ?? '';

        return '' !== $char && str_contains(self::SPACE_LEADS, $char) && 0 === $this->multibyteSpaceLengthAt($offset);
    }

    private function afterBigIntSuffix(int $offset): int
    {
        return $offset + strspn($this->source, self::BIGINT_SUFFIX, $offset, 1);
    }

    private function exponentLength(int $offset): int
    {
        if (!in_array($this->source[$offset] ?? '', self::EXPONENTS, true)) {
            return 0;
        }

        $sign = strspn($this->source, '+-', $offset + 1, 1);
        $digits = strspn($this->source, self::DECIMAL_DIGITS, $offset + 1 + $sign);

        return 0 === $digits ? 0 : 1 + $sign + $digits;
    }

    // Because a backslash cannot escape a line end in a pattern, an unclosed pattern still ends at that line end.
    private function afterPatternEscape(int $offset): int
    {
        return $this->lineEndsAt($offset + 1) ? $offset + 1 : min($offset + 2, $this->length);
    }

    private function afterCharacterClass(int $offset): int
    {
        while (($offset += strcspn($this->source, "]\\\n\r".self::SEPARATOR_LEAD, $offset)) < $this->length) {
            if (']' === $this->source[$offset]) {
                return $offset + 1;
            }

            if ($this->lineEndsAt($offset)) {
                return $offset;
            }

            $offset = '\\' === $this->source[$offset] ? $this->afterPatternEscape($offset) : $offset + 1;
        }

        return $this->length;
    }
}
