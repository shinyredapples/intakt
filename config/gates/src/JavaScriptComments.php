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
        return iterator_to_array($this->code($source, 0, false), false);
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function code(string $source, int $offset, bool $inTemplate): Generator
    {
        $length = strlen($source);
        $depth = 0;
        $regexMayFollow = true;

        while (true) {
            $stop = $offset + strcspn($source, self::CODE_STOPS, $offset);
            $regexMayFollow = $this->regexMayFollow(substr($source, $offset, $stop - $offset), $regexMayFollow);

            if ($stop >= $length) {
                return $length;
            }

            $char = $source[$stop];

            if ($inTemplate && 0 === $depth && '}' === $char) {
                return $stop + 1;
            }

            [$offset, $regexMayFollow] = match ($char) {
                "'", '"' => [$this->afterQuoted($source, $stop + 1, $char), false],
                '`' => [yield from $this->template($source, $stop + 1), false],
                '/' => yield from $this->slash($source, $stop, $regexMayFollow),
                ')', ']' => [$stop + 1, false],
                default => [$stop + 1, true],
            };
            $depth = $this->depthAfter($char, $depth);
        }
    }

    /**
     * @return Generator<int, array{int, string}, mixed, array{int, bool}>
     */
    private function slash(string $source, int $offset, bool $regexMayFollow): Generator
    {
        $next = $source[$offset + 1] ?? '';

        if ('/' === $next) {
            $end = $offset + strcspn($source, self::LINE_ENDS, $offset);

            yield [$offset, substr($source, $offset, $end - $offset)];

            return [$end, $regexMayFollow];
        }

        if ('*' === $next) {
            $end = strpos($source, '*/', $offset + 2);
            $end = false === $end ? strlen($source) : $end + 2;

            yield [$offset, substr($source, $offset, $end - $offset)];

            return [$end, $regexMayFollow];
        }

        return $regexMayFollow ? [$this->afterRegex($source, $offset + 1), false] : [$offset + 1, true];
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
                '{' === ($source[$offset + 1] ?? '') => yield from $this->code($source, $offset + 2, true),
                default => $offset + 1,
            };
        }

        return min($offset + 1, $length);
    }

    private function afterRegex(string $source, int $offset): int
    {
        $length = strlen($source);
        $inClass = false;

        while (($offset += strcspn($source, "/\\[]\r\n", $offset)) < $length) {
            $char = $source[$offset];

            if ('\\' === $char) {
                $offset += 2;

                continue;
            }

            if (('/' === $char && !$inClass) || str_contains(self::LINE_ENDS, $char)) {
                return $offset + 1;
            }

            $inClass = match ($char) {
                '[' => true,
                ']' => false,
                default => $inClass,
            };
            ++$offset;
        }

        return $length;
    }

    private function regexMayFollow(string $code, bool $before): bool
    {
        $code = rtrim($code);

        if ('' === $code) {
            return $before;
        }

        if (!str_contains(self::WORD_CHARACTERS, $code[-1])) {
            return true;
        }

        $word = substr($code, strlen($code) - strspn(strrev($code), self::WORD_CHARACTERS));

        return in_array($word, self::KEYWORDS_BEFORE_AN_EXPRESSION, true);
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
