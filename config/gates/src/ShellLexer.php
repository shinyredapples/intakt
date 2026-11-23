<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a `#` starts a comment depends on the quotes and `${ }` expansions open around it, the lexer keeps them on a stack for one source.
final class ShellLexer
{
    private const int DOUBLE_QUOTES = 0;
    private const int EXPANSION = 1;
    // Because bash reads a `'` in a `${ }` inside double quotes as a plain character, such an expansion is kept apart from an unquoted one.
    private const int QUOTED_EXPANSION = 2;
    private const array QUOTED_FRAMES = [self::DOUBLE_QUOTES, self::QUOTED_EXPANSION];
    // Because a `#` starts a comment only at the start of a word, these are the characters a word starts after.
    private const string WORD_BREAKS = " \t\n;&|()<>";
    private const string DOUBLE_QUOTED_SPECIALS = '\\"$';

    private readonly int $length;
    private bool $atWordStart = true;

    /**
     * @var list<int>
     */
    private array $frames = [];

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @return list<array{int, string}>
     */
    public function comments(): array
    {
        $offset = 0;

        while ($offset < $this->length) {
            $offset = $this->afterToken($offset);
        }

        return $this->comments;
    }

    private function afterToken(int $offset): int
    {
        $frame = array_last($this->frames);

        return match ($frame) {
            null => $this->afterUnquoted($offset),
            self::DOUBLE_QUOTES => $this->afterDoubleQuoted($offset),
            default => $this->afterInExpansion($offset, $frame),
        };
    }

    private function afterUnquoted(int $offset): int
    {
        $char = $this->source[$offset];

        if ('#' === $char && $this->atWordStart) {
            return $this->afterComment($offset);
        }

        if ('\\' === $char) {
            return $this->afterEscape($offset);
        }

        $this->atWordStart = str_contains(self::WORD_BREAKS, $char);

        return $this->afterQuoteOrDollar($offset, $char);
    }

    private function afterDoubleQuoted(int $offset): int
    {
        $char = $this->source[$offset];

        return match (true) {
            '\\' === $char => $offset + 2,
            '"' === $char => $this->afterClosing($offset),
            $this->expansionOpensAt($offset) => $this->afterOpening(self::QUOTED_EXPANSION, $offset + 2),
            default => $offset + 1 + strcspn($this->source, self::DOUBLE_QUOTED_SPECIALS, $offset + 1),
        };
    }

    private function afterInExpansion(int $offset, int $frame): int
    {
        $char = $this->source[$offset];

        return match (true) {
            '}' === $char => $this->afterClosing($offset),
            '\\' === $char => $offset + 2,
            "'" === $char && self::QUOTED_EXPANSION === $frame => $offset + 1,
            default => $this->afterQuoteOrDollar($offset, $char),
        };
    }

    private function afterComment(int $offset): int
    {
        $end = $offset + strcspn($this->source, "\n", $offset);
        $this->comments[] = [$offset, substr($this->source, $offset, $end - $offset)];

        return $end;
    }

    // Because a backslash before a line feed joins two lines, it leaves the word as it was.
    private function afterEscape(int $offset): int
    {
        if ("\n" !== ($this->source[$offset + 1] ?? '')) {
            $this->atWordStart = false;
        }

        return $offset + 2;
    }

    private function afterQuoteOrDollar(int $offset, string $char): int
    {
        return match (true) {
            "'" === $char => $this->afterSingleQuoted($offset + 1),
            '"' === $char => $this->afterOpening(self::DOUBLE_QUOTES, $offset + 1),
            $this->expansionOpensAt($offset) => $this->afterOpening($this->expansionKindHere(), $offset + 2),
            '$' === $char && "'" === ($this->source[$offset + 1] ?? '') => $this->afterAnsiCQuoted($offset + 2),
            default => $offset + 1,
        };
    }

    private function afterClosing(int $offset): int
    {
        array_pop($this->frames);

        return $offset + 1;
    }

    private function expansionOpensAt(int $offset): bool
    {
        return '$' === $this->source[$offset] && '{' === ($this->source[$offset + 1] ?? '');
    }

    private function afterOpening(int $frame, int $offset): int
    {
        $this->frames[] = $frame;

        return $offset;
    }

    private function afterSingleQuoted(int $offset): int
    {
        $end = strpos($this->source, "'", $offset);

        return false === $end ? $this->length : $end + 1;
    }

    private function expansionKindHere(): int
    {
        return in_array(array_last($this->frames), self::QUOTED_FRAMES, true) ? self::QUOTED_EXPANSION : self::EXPANSION;
    }

    private function afterAnsiCQuoted(int $offset): int
    {
        while ($offset < $this->length) {
            $offset += strcspn($this->source, "\\'", $offset);

            if ("'" === ($this->source[$offset] ?? '')) {
                return $offset + 1;
            }

            $offset += 2;
        }

        return $this->length;
    }
}
