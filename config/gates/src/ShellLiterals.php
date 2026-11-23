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
        return match (true) {
            '$((' === substr($this->source, $offset, 3) => $this->afterArithmetic($offset + 3, 2),
            '${' === substr($this->source, $offset, 2) => $this->afterParameter($offset + 2),
            default => $offset + 1,
        };
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
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     */
    public function afterDocumentBody(int $offset, array $document): int
    {
        while ($offset < $this->length) {
            [$line, $offset] = $this->documentLineAt($offset, $document);

            if ($line === $document['delimiter']) {
                return $offset;
            }
        }

        return $this->length;
    }

    public function afterDoubleQuotedText(int $offset): int
    {
        while (($offset += strcspn($this->source, '"\\$`', $offset)) < $this->length) {
            $char = $this->source[$offset];

            if ('"' === $char) {
                return $offset + 1;
            }

            $offset = match ($char) {
                '\\' => min($offset + 2, $this->length),
                '`' => $this->afterEscapedText($offset + 1, '`'),
                default => $this->afterExpansion($offset),
            };
        }

        return $this->length;
    }

    // Because a `#` in `${#name}` or `${name#prefix}` is an operator, a parameter expansion is skipped to its own closing brace, and a brace in a quoted default such as `${name:-'}'}` counts for nothing.
    private function afterParameter(int $offset): int
    {
        $depth = 1;

        while (($offset += strcspn($this->source, "{}\\'\"`\$", $offset)) < $this->length) {
            $char = $this->source[$offset];
            $offset = match ($char) {
                '\\' => min($offset + 2, $this->length),
                "'" => $this->afterSingleQuoted($offset + 1),
                '"' => $this->afterDoubleQuotedText($offset + 1),
                '`' => $this->afterEscapedText($offset + 1, '`'),
                '$' => $this->afterExpansion($offset),
                default => $offset + 1,
            };
            $depth += match ($char) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if (0 === $depth) {
                return $offset;
            }
        }

        return $this->length;
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

    /**
     * @param array{delimiter: string, joinsLines: bool, stripsTabs: bool} $document
     *
     * @return array{string, int}
     */
    private function documentLineAt(int $offset, array $document): array
    {
        // Because only whether the line equals the delimiter matters, a line grows in place to no more than one byte past it, so a long chain of joined lines under a long delimiter is read in linear time.
        $kept = strlen($document['delimiter']) + 1;
        $line = '';

        do {
            $end = $offset + strcspn($this->source, "\n", $offset);
            $start = '' === $line && $document['stripsTabs'] ? $offset + strspn($this->source, "\t", $offset, $end - $offset) : $offset;
            $continues = $document['joinsLines'] && $end < $this->length && $this->endsInAnEscape($start, $end);
            $room = $kept - strlen($line);

            if ($room > 0) {
                $line .= substr($this->source, $start, min($end - $start - ($continues ? 1 : 0), $room));
            }

            $offset = $end + 1;
        } while ($continues);

        return [$line, min($offset, $this->length)];
    }

    // Because a backslash escapes the next one, only an odd run of backslashes before the line end joins the next line.
    private function endsInAnEscape(int $start, int $end): bool
    {
        $line = substr($this->source, $start, $end - $start);

        return 1 === (strlen($line) - strlen(rtrim($line, '\\'))) % 2;
    }
}
