<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;

final readonly class JavaScriptComments implements CommentLanguage
{
    private const string CODE_STOPS = '\'"`/(){}[]';
    private const string WORD_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';
    private const string LINE_ENDS = "\r\n";
    // Because JavaScript also ends a line at these two characters, a line comment stops there.
    private const array SEPARATOR_LINE_ENDS = ["\u{2028}", "\u{2029}"];
    // Because after these words an expression starts, so a slash there opens a regular expression, while after any other word it divides.
    private const array KEYWORDS_BEFORE_AN_EXPRESSION = ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'];
    // Because these words start a statement, a brace after them opens a block and not an object literal.
    private const array KEYWORDS_BEFORE_A_BLOCK = ['do', 'else'];
    // Because the parentheses after these words hold a condition, a slash after them starts the statement's body.
    private const array KEYWORDS_BEFORE_A_CONDITION = ['if', 'while', 'for', 'with'];
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
        $slash = JavaScriptSlash::Regex;
        // Because what a slash after a closing bracket means depends on its opening one, each open bracket keeps that meaning here.
        $closings = [];
        $before = '';

        while (true) {
            $stop = $offset + strcspn($source, self::CODE_STOPS, $offset);
            $code = rtrim(substr($source, $offset, $stop - $offset));

            if ('' !== $code) {
                $before = $code;
                $slash = $this->slashAfter($code);
            }

            if ($stop >= $length) {
                return $length;
            }

            $char = $source[$stop];

            if ([] === $closings && $char === $closer) {
                return $stop + 1;
            }

            if (str_contains('([{', $char)) {
                $closings[] = $this->slashAfterClosing($char, $before);
            }

            [$offset, $slash] = match ($char) {
                "'", '"' => [$this->afterQuoted($source, $stop + 1, $char), JavaScriptSlash::Division],
                '`' => [yield from $this->template($source, $stop + 1), JavaScriptSlash::Division],
                '/' => yield from $this->slash($source, $stop, $slash),
                ')', ']', '}' => [$stop + 1, array_pop($closings) ?? JavaScriptSlash::Division],
                default => [$stop + 1, JavaScriptSlash::Regex],
            };
            $before = '/' === $char && in_array($source[$stop + 1] ?? '', ['/', '*'], true) ? $before : $char;
        }
    }

    private function slashAfterClosing(string $opening, string $before): JavaScriptSlash
    {
        $word = $this->wordAtEnd($before);

        return match (true) {
            '(' === $opening => in_array($word, self::KEYWORDS_BEFORE_A_CONDITION, true) && !$this->isProperty($before, $word) ? JavaScriptSlash::Regex : JavaScriptSlash::Division,
            '[' === $opening => JavaScriptSlash::Division,
            default => $this->opensBlock($before, $word) ? JavaScriptSlash::Regex : JavaScriptSlash::Division,
        };
    }

    // Because a brace where an expression is expected opens an object literal, which a slash then divides, while any other brace opens a block.
    private function opensBlock(string $before, string $word): bool
    {
        if ('' === $before || str_ends_with($before, '=>') || str_contains(';{})', $before[-1])) {
            return true;
        }

        if ('' === $word) {
            return false;
        }

        return in_array($word, self::KEYWORDS_BEFORE_A_BLOCK, true) || !in_array($word, self::KEYWORDS_BEFORE_AN_EXPRESSION, true);
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

    // Because an unescaped line end cannot stand in a quoted string, a string left open ends there instead of hiding the rest of the file.
    // A backslash before a carriage return and line feed continues the string over both.
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

    // Because a postfix increment or decrement ends an operand, a slash after it divides.
    private function slashAfter(string $code): JavaScriptSlash
    {
        $word = $this->wordAtEnd($code);

        if ('' === $word) {
            return str_ends_with($code, '++') || str_ends_with($code, '--') ? JavaScriptSlash::Division : JavaScriptSlash::Regex;
        }

        return in_array($word, self::KEYWORDS_BEFORE_AN_EXPRESSION, true) && !$this->isProperty($code, $word) ? JavaScriptSlash::Regex : JavaScriptSlash::Division;
    }

    // Because a keyword after a dot is only a property name, it does not start an expression.
    private function isProperty(string $code, string $word): bool
    {
        return '.' === substr($code, -strlen($word) - 1, 1);
    }

    // Because identifiers may hold any Unicode letter, every byte of a multibyte character counts as part of a word.
    private function wordAtEnd(string $code): string
    {
        $start = strlen($code);

        while ($start > 0 && (ord($code[$start - 1]) >= 0x80 || str_contains(self::WORD_CHARACTERS, $code[$start - 1]))) {
            --$start;
        }

        return substr($code, $start);
    }
}
