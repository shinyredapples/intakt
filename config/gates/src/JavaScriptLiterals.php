<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class JavaScriptLiterals
{
    public const string LINE_SEPARATOR = "\u{2028}";
    public const string PARAGRAPH_SEPARATOR = "\u{2029}";
    private const string SEPARATOR_LEAD = "\xE2";
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
        // Because any byte of a multibyte character may be part of an identifier, except the lead byte that U+2028 and U+2029 share, which is checked on its own.
        $this->wordBytes = self::WORD_ASCII.implode('', array_map(chr(...), [...range(0x80, 0xE1), ...range(0xE3, 0xFF)]));
    }

    public function separatorAt(int $offset): bool
    {
        $bytes = substr($this->source, $offset, 3);

        return self::LINE_SEPARATOR === $bytes || self::PARAGRAPH_SEPARATOR === $bytes;
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
        $char = $this->source[$offset];

        return ctype_digit($char) || ('.' === $char && ctype_digit($this->source[$offset + 1] ?? ''));
    }

    public function afterWord(int $offset): int
    {
        $end = $offset + strspn($this->source, $this->wordBytes, $offset);

        while ($this->wordContinuesAt($end)) {
            $end += 1 + strspn($this->source, $this->wordBytes, $end + 1);
        }

        return $end;
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
                '\\' => $this->afterEscape($offset),
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

    private function wordContinuesAt(int $offset): bool
    {
        return self::SEPARATOR_LEAD === ($this->source[$offset] ?? '') && !$this->separatorAt($offset);
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

    private function afterBigIntSuffix(int $offset): int
    {
        return $offset + strspn($this->source, self::BIGINT_SUFFIX, $offset, 1);
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

            $offset = '\\' === $this->source[$offset] ? $this->afterEscape($offset) : $offset + 1;
        }

        return $this->length;
    }
}
