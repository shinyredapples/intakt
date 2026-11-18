<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;

final readonly class JavaScriptComments implements CommentLanguage
{
    private const string CODE_STOPS = '\'"`/(){}[]';
    private const string LINE_ENDS = "\r\n";
    // Because JavaScript also ends a line at these two characters, a line comment stops there.
    private const array SEPARATOR_LINE_ENDS = ["\u{2028}", "\u{2029}"];
    // Because JavaScript shares PHP's comment syntax except the hash, a marker behind nested comment syntax counts as it does in PHP.
    private const string LINE_SYNTAX = '~\G(?://++|/\*++|\*++(?!/))\s*+~';

    public function lineSyntax(): string
    {
        return self::LINE_SYNTAX;
    }

    public function commentsIn(string $source): array
    {
        return iterator_to_array($this->code($source, 0, null), false);
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function code(string $source, int $offset, ?string $closer): Generator
    {
        $length = strlen($source);
        $context = new JavaScriptContext();

        while (true) {
            $stop = $offset + strcspn($source, self::CODE_STOPS, $offset);
            $context = $context->afterCode(rtrim(substr($source, $offset, $stop - $offset)));

            if ($stop >= $length) {
                return $length;
            }

            if ($context->isOutermost() && $source[$stop] === $closer) {
                return $stop + 1;
            }

            [$offset, $context] = yield from $this->afterStop($source, $stop, $context);
        }
    }

    /**
     * @return Generator<int, array{int, string}, mixed, array{int, JavaScriptContext}>
     */
    private function afterStop(string $source, int $stop, JavaScriptContext $context): Generator
    {
        $char = $source[$stop];

        return match ($char) {
            "'", '"' => [$this->afterQuoted($source, $stop + 1, $char), $context->afterValue()],
            '`' => [yield from $this->template($source, $stop + 1), $context->afterValue()],
            '/' => yield from $this->slash($source, $stop, $context),
            '(', '[', '{' => [$stop + 1, $context->afterOpening($char)],
            default => [$stop + 1, $context->afterClosing($char)],
        };
    }

    /**
     * @return Generator<int, array{int, string}, mixed, array{int, JavaScriptContext}>
     */
    private function slash(string $source, int $offset, JavaScriptContext $context): Generator
    {
        $end = match ($source[$offset + 1] ?? '') {
            '/' => $this->afterLineComment($source, $offset),
            '*' => $this->afterBlockComment($source, $offset + 2),
            default => null,
        };

        if (null !== $end) {
            yield [$offset, substr($source, $offset, $end - $offset)];

            return [$end, $context];
        }

        return $context->slashStartsARegex() ? [$this->afterRegex($source, $offset + 1), $context->afterValue()] : [$offset + 1, $context->afterDivision()];
    }

    private function afterLineComment(string $source, int $offset): int
    {
        $length = strcspn($source, self::LINE_ENDS, $offset);
        $line = substr($source, $offset, $length);

        foreach (self::SEPARATOR_LINE_ENDS as $separator) {
            $at = strpos($line, $separator);
            $length = false === $at ? $length : min($length, $at);
        }

        return $offset + $length;
    }

    private function afterBlockComment(string $source, int $offset): int
    {
        $end = strpos($source, '*/', $offset);

        return false === $end ? strlen($source) : $end + 2;
    }

    // Because only an escaped line end (a backslash before CR LF escapes both) may stand in a quoted string, a string left open ends at a line end instead of hiding the rest of the file.
    private function afterQuoted(string $source, int $offset, string $quote): int
    {
        $length = strlen($source);

        while (($offset += strcspn($source, $quote."\\\r\n", $offset)) < $length && '\\' === $source[$offset]) {
            $offset += "\r\n" === substr($source, $offset + 1, 2) ? 3 : 2;
        }

        return min($offset + 1, $length);
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function template(string $source, int $offset): Generator
    {
        $length = strlen($source);

        while (($offset += strcspn($source, '`\\$', $offset)) < $length && '`' !== $source[$offset]) {
            $offset = match (true) {
                '\\' === $source[$offset] => $offset + 2,
                '{' === ($source[$offset + 1] ?? '') => yield from $this->code($source, $offset + 2, '}'),
                default => $offset + 1,
            };
        }

        return min($offset + 1, $length);
    }

    private function afterRegex(string $source, int $offset): int
    {
        $length = strlen($source);

        while (($offset += strcspn($source, "/\\[\r\n", $offset)) < $length) {
            $char = $source[$offset];

            if ('/' === $char || str_contains(self::LINE_ENDS, $char)) {
                return $offset + 1;
            }

            $offset = '\\' === $char ? $offset + 2 : $this->afterClass($source, $offset + 1);
        }

        return $length;
    }

    // Because a slash inside a character class does not close the regular expression, the class is read to its own end.
    private function afterClass(string $source, int $offset): int
    {
        $length = strlen($source);

        while (($offset += strcspn($source, "]\\\r\n", $offset)) < $length && '\\' === $source[$offset]) {
            $offset += 2;
        }

        return $offset < $length && ']' === $source[$offset] ? $offset + 1 : $offset;
    }
}
