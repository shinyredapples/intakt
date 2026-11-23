<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;

final readonly class TwigComments implements CommentLanguage
{
    private const string TAG_START = '/\{(\{|%|##(?!\})|#)[-~]?/';
    private const string VERBATIM_START = '/\s*+verbatim\s*+[-~]?%\}/A';
    private const string VERBATIM_END = '/\{%[-~]?\s*+endverbatim\s*+[-~]?%\}/';
    private const array TAG_ENDS = ['{' => '}}', '%' => '%}'];
    private const string COMMENT_END = '#}';
    private const string EXPRESSION_STOPS = '\'"#()[]{}%';
    private const string OPENING_BRACKETS = '([{';
    private const string CLOSING_BRACKETS = ')]}';
    // Because Twig's own delimiters are already cut off, only a leading star before whitespace is layout, in any comment as in part 1's PHP scan; a `//` or `#` is the comment's text, and a star right before a word is Markdown emphasis.
    private const string LINE_SYNTAX = '~\G\*++(?:\s++|$)~';

    public function lineSyntax(): string
    {
        return self::LINE_SYNTAX;
    }

    public function holdsPhpCode(): bool
    {
        return false;
    }

    public function commentsIn(string $source): array
    {
        return iterator_to_array($this->commentsFrom($source), false);
    }

    /**
     * @return Generator<int, array{int, string}>
     */
    private function commentsFrom(string $source): Generator
    {
        $offset = 0;

        while (null !== $tag = $this->matchIn(self::TAG_START, $source, $offset)) {
            $inside = $tag[0][1] + strlen($tag[0][0]);
            $kind = $tag[1][0][0];

            if ('#' === $kind) {
                $offset = yield from $this->comment($source, $inside);

                continue;
            }

            $offset = $this->afterVerbatim($source, $inside, $kind) ?? yield from $this->expression($source, $inside, self::TAG_ENDS[$kind]);
        }
    }

    /**
     * @return array<array{string, int}>|null
     */
    private function matchIn(string $pattern, string $source, int $offset): ?array
    {
        $result = preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset);

        if (false === $result) {
            throw new ScopeFailure('cannot read a template: '.preg_last_error_msg());
        }

        return 1 === $result ? $match : null;
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function comment(string $source, int $inside): Generator
    {
        $end = strpos($source, self::COMMENT_END, $inside);
        $end = false === $end ? strlen($source) : $end;

        yield [$inside, substr($source, $inside, $end - $inside)];

        return min($end + strlen(self::COMMENT_END), strlen($source));
    }

    // Because Twig prints a verbatim block as it stands, a comment inside it is template text.
    private function afterVerbatim(string $source, int $inside, string $kind): ?int
    {
        if ('%' !== $kind || null === $this->matchIn(self::VERBATIM_START, $source, $inside)) {
            return null;
        }

        $end = $this->matchIn(self::VERBATIM_END, $source, $inside);

        return null === $end ? strlen($source) : $end[0][1] + strlen($end[0][0]);
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function expression(string $source, int $offset, string $closer): Generator
    {
        $length = strlen($source);
        $depth = 0;

        while (($offset += strcspn($source, self::EXPRESSION_STOPS, $offset)) < $length) {
            $char = $source[$offset];

            if (0 === $depth && substr($source, $offset, strlen($closer)) === $closer) {
                return $offset + strlen($closer);
            }

            $offset = match ($char) {
                "'" => $this->afterSingleQuoted($source, $offset + 1),
                '"' => yield from $this->doubleQuoted($source, $offset + 1),
                // Because Twig reads a hash inside a tag as a comment up to the end of its line.
                '#' => yield from $this->inlineComment($source, $offset + 1),
                default => $offset + 1,
            };
            $depth = $this->depthAfter($char, $depth);
        }

        return $length;
    }

    private function afterSingleQuoted(string $source, int $offset): int
    {
        $length = strlen($source);

        while (($offset += strcspn($source, "'\\", $offset)) < $length && "'" !== $source[$offset]) {
            $offset += 2;
        }

        return min($offset + 1, $length);
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function doubleQuoted(string $source, int $offset): Generator
    {
        $length = strlen($source);

        while (($offset += strcspn($source, '"\\#', $offset)) < $length && '"' !== $source[$offset]) {
            $offset = match (true) {
                '\\' === $source[$offset] => $offset + 2,
                '{' === ($source[$offset + 1] ?? '') => yield from $this->expression($source, $offset + 2, '}'),
                default => $offset + 1,
            };
        }

        return min($offset + 1, $length);
    }

    /**
     * @return Generator<int, array{int, string}, mixed, int>
     */
    private function inlineComment(string $source, int $inside): Generator
    {
        // Because Twig reads `##` inside a tag as the opener of an inline documentation comment, both hashes are delimiter.
        $inside += strspn($source, '#', $inside, 1);
        $end = $inside + strcspn($source, "\r\n", $inside);

        yield [$inside, substr($source, $inside, $end - $inside)];

        return $end;
    }

    private function depthAfter(string $char, int $depth): int
    {
        if (str_contains(self::OPENING_BRACKETS, $char)) {
            return $depth + 1;
        }

        return str_contains(self::CLOSING_BRACKETS, $char) ? max(0, $depth - 1) : $depth;
    }
}
