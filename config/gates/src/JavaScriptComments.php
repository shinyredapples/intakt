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

    public function __construct(private JavaScriptSlashes $slashes = new JavaScriptSlashes())
    {
    }

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
        $slash = JavaScriptSlash::Regex;
        // Because what a slash after a closing bracket means depends on its opening one, each open bracket keeps that meaning here.
        $closings = [];
        $before = '';

        while (true) {
            $stop = $offset + strcspn($source, self::CODE_STOPS, $offset);
            $code = rtrim(substr($source, $offset, $stop - $offset));
            [$slash, $before] = '' === $code ? [$slash, $before] : [$this->slashes->after($code), $code];

            if ($stop >= $length) {
                return $length;
            }

            if ([] === $closings && $source[$stop] === $closer) {
                return $stop + 1;
            }

            [$offset, $slash, $closings] = yield from $this->afterStop($source, $stop, $slash, $closings, $before);
            $before = $this->beforeNextStop($source, $stop, $before);
        }
    }

    /**
     * @param list<JavaScriptSlash> $closings
     *
     * @return Generator<int, array{int, string}, mixed, array{int, JavaScriptSlash, list<JavaScriptSlash>}>
     */
    private function afterStop(string $source, int $stop, JavaScriptSlash $slash, array $closings, string $before): Generator
    {
        $char = $source[$stop];

        return match ($char) {
            "'", '"' => [$this->afterQuoted($source, $stop + 1, $char), JavaScriptSlash::Division, $closings],
            '`' => [yield from $this->template($source, $stop + 1), JavaScriptSlash::Division, $closings],
            '/' => [...yield from $this->slash($source, $stop, $slash), $closings],
            '(', '[', '{' => [$stop + 1, JavaScriptSlash::Regex, [...$closings, $this->slashes->afterClosing($char, $before)]],
            default => [$stop + 1, array_last($closings) ?? JavaScriptSlash::Division, array_slice($closings, 0, -1)],
        };
    }

    // Because a comment is not code, the code before it stays what the next stop follows.
    private function beforeNextStop(string $source, int $stop, string $before): string
    {
        return '/' === $source[$stop] && in_array($source[$stop + 1] ?? '', ['/', '*'], true) ? $before : $source[$stop];
    }

    /**
     * @return Generator<int, array{int, string}, mixed, array{int, JavaScriptSlash}>
     */
    private function slash(string $source, int $offset, JavaScriptSlash $slash): Generator
    {
        $end = match ($source[$offset + 1] ?? '') {
            '/' => $this->afterLineComment($source, $offset),
            '*' => $this->afterBlockComment($source, $offset + 2),
            default => null,
        };

        if (null !== $end) {
            yield [$offset, substr($source, $offset, $end - $offset)];

            return [$end, $slash];
        }

        return JavaScriptSlash::Regex === $slash ? [$this->afterRegex($source, $offset + 1), JavaScriptSlash::Division] : [$offset + 1, JavaScriptSlash::Regex];
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

    // Because an unescaped line end cannot stand in a quoted string, a string left open ends there instead of hiding the rest of the file,
    // while a backslash before a carriage return and line feed escapes both, so the string goes on.
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
