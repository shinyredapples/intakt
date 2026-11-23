<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ShellLiterals
{
    public const string BLANKS = " \t";
    // Because bash ends a word only at a metacharacter, a here-document delimiter runs up to one.
    public const string METACHARACTERS = " \t\n;|&()<>";

    public int $length;

    public function __construct(public string $source)
    {
        $this->length = strlen($source);
    }

    public function afterSingleQuoted(int $offset): int
    {
        $end = strpos($this->source, "'", $offset);

        return false === $end ? $this->length : $end + 1;
    }

    // Because ANSI-C quoting and backticks both end at their closer unless a backslash escapes it.
    public function afterEscapedText(int $offset, string $closer): int
    {
        while (($offset += strcspn($this->source, $closer.'\\', $offset)) < $this->length) {
            if ($closer === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset = min($offset + 2, $this->length);
        }

        return $this->length;
    }

    public function afterExpansion(int $offset): int
    {
        return '$((' === substr($this->source, $offset, 3) ? $this->afterArithmetic($offset + 3, 2) : $offset + 1;
    }

    // Because a `<<` or `#` in arithmetic is a shift or a base, arithmetic is skipped to the parenthesis that closes it.
    public function afterArithmetic(int $offset, int $depth): int
    {
        while (($offset += strcspn($this->source, '()', $offset)) < $this->length) {
            $depth += '(' === $this->source[$offset] ? 1 : -1;
            ++$offset;

            if (0 === $depth) {
                return $offset;
            }
        }

        return $this->length;
    }

    // Because bash takes the delimiter with its quotes removed and reads the body as it stands once any part of the delimiter was quoted.
    /**
     * @return array{string, int, bool}
     */
    public function documentDelimiterAt(int $offset): array
    {
        $offset += strspn($this->source, self::BLANKS, $offset);
        $delimiter = '';
        $quoted = false;

        while ($offset < $this->length && !str_contains(self::METACHARACTERS, $this->source[$offset])) {
            [$part, $offset, $partQuoted] = $this->delimiterPartAt($offset);
            $delimiter .= $part;
            $quoted = $quoted || $partQuoted;
        }

        return [$delimiter, $offset, $quoted];
    }

    /**
     * @return array{string, int, bool}
     */
    private function delimiterPartAt(int $offset): array
    {
        // Because the `$` of ANSI-C or locale quoting is removed with the quotes, `$'EOF'` is the delimiter `EOF`.
        if ('$' === $this->source[$offset] && in_array($this->source[$offset + 1] ?? '', ["'", '"'], true)) {
            ++$offset;
        }

        $char = $this->source[$offset];

        return match ($char) {
            "'", '"' => $this->quotedDelimiterPart($offset, $char),
            '\\' => [substr($this->source, $offset + 1, 1), min($offset + 2, $this->length), true],
            default => [$char, $offset + 1, false],
        };
    }

    /**
     * @return array{string, int, bool}
     */
    private function quotedDelimiterPart(int $offset, string $quote): array
    {
        $end = strpos($this->source, $quote, $offset + 1);
        $end = false === $end ? $this->length : $end;

        return [substr($this->source, $offset + 1, $end - $offset - 1), min($end + 1, $this->length), true];
    }
}
