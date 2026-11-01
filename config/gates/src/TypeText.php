<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TypeText
{
    private const string OPENING = '<{([';
    private const string CLOSING = '>})]';

    public function remainderAfterType(string $text): ?string
    {
        $text = trim($text);
        $end = $this->endOfType($text);

        return 0 === $end ? null : trim(substr($text, $end));
    }

    private function endOfType(string $text): int
    {
        $depth = 0;
        $length = strlen($text);

        for ($position = 0; $position < $length; ++$position) {
            $depth += $this->depthChange($text[$position]);

            if (0 === $depth && $this->typeEndsAfter($text, $position)) {
                return $position + 1;
            }
        }

        return $length;
    }

    private function depthChange(string $character): int
    {
        return match (true) {
            str_contains(self::OPENING, $character) => 1,
            str_contains(self::CLOSING, $character) => -1,
            default => 0,
        };
    }

    private function typeEndsAfter(string $text, int $position): bool
    {
        $next = substr($text, $position + 1);

        if ('' === $next || !ctype_space($next[0])) {
            return '' === $next;
        }

        return !$this->continuesWithOperator(rtrim(substr($text, 0, $position + 1)), ltrim($next));
    }

    private function continuesWithOperator(string $before, string $after): bool
    {
        return str_ends_with($before, '|') || str_ends_with($before, '&') || str_ends_with($before, ':') || str_starts_with($after, '|') || $this->startsWithIntersection($after) || str_starts_with($after, ':');
    }

    private function startsWithIntersection(string $after): bool
    {
        return str_starts_with($after, '&') && 1 !== preg_match('/^&(\.\.\.)?\$/', $after);
    }
}
