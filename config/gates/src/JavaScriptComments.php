<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;

final readonly class JavaScriptComments implements CommentLanguage
{
    private const string CODE_STOPS = '\'"`/(){}[]';
    private const string WORD_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';
    private const string LINE_ENDS = "\r\n";
    // Because after these words an expression starts, so a slash there opens a regular expression, while after any other word it divides.
    private const array KEYWORDS_BEFORE_AN_EXPRESSION = ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'];
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
        $depth = 0;
        $slash = JavaScriptSlash::Regex;

        while (true) {
            $stop = $offset + strcspn($source, self::CODE_STOPS, $offset);
            $slash = $this->slashAfter(substr($source, $offset, $stop - $offset)) ?? $slash;

            if ($stop >= $length) {
                return $length;
            }

            $char = $source[$stop];

            if (0 === $depth && $char === $closer) {
                return $stop + 1;
            }

            [$offset, $slash] = match ($char) {
                "'", '"' => [$this->afterQuoted($source, $stop + 1, $char), JavaScriptSlash::Division],
                '`' => [yield from $this->template($source, $stop + 1), JavaScriptSlash::Division],
                '/' => yield from $this->slash($source, $stop, $slash),
                ')', ']' => [$stop + 1, JavaScriptSlash::Division],
                default => [$stop + 1, JavaScriptSlash::Regex],
            };
            $depth = $this->depthAfter($char, $depth);
        }
    }

    /**
     * @return Generator<int, array{int, string}, mixed, array{int, JavaScriptSlash}>
     */
    private function slash(string $source, int $offset, JavaScriptSlash $slash): Generator
    {
        $end = match ($source[$offset + 1] ?? '') {
            '/' => $offset + strcspn($source, self::LINE_ENDS, $offset),
            '*' => $this->afterBlockComment($source, $offset + 2),
            default => null,
        };

        if (null !== $end) {
            yield [$offset, substr($source, $offset, $end - $offset)];

            return [$end, $slash];
        }

        return JavaScriptSlash::Regex === $slash ? [$this->afterRegex($source, $offset + 1), JavaScriptSlash::Division] : [$offset + 1, JavaScriptSlash::Regex];
    }

    private function afterBlockComment(string $source, int $offset): int
    {
        $end = strpos($source, '*/', $offset);

        return false === $end ? strlen($source) : $end + 2;
    }

    // Because an unescaped line end cannot stand in a quoted string, a string left open ends there instead of hiding the rest of the file.
    private function afterQuoted(string $source, int $offset, string $quote): int
    {
        $length = strlen($source);

        while (($offset += strcspn($source, $quote."\\\r\n", $offset)) < $length && '\\' === $source[$offset]) {
            $offset += 2;
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

    private function slashAfter(string $code): ?JavaScriptSlash
    {
        $code = rtrim($code);

        if ('' === $code) {
            return null;
        }

        if (!str_contains(self::WORD_CHARACTERS, $code[-1])) {
            return JavaScriptSlash::Regex;
        }

        $word = substr($code, strlen($code) - strspn(strrev($code), self::WORD_CHARACTERS));

        return in_array($word, self::KEYWORDS_BEFORE_AN_EXPRESSION, true) ? JavaScriptSlash::Regex : JavaScriptSlash::Division;
    }

    private function depthAfter(string $char, int $depth): int
    {
        return match ($char) {
            '{' => $depth + 1,
            '}' => max(0, $depth - 1),
            default => $depth,
        };
    }
}
