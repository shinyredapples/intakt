<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use InvalidArgumentException;

final readonly class SourceLine
{
    // A whole line with a full commit id, so a sentence, a quote or code that mentions one is prose and not a source line.
    private const string LINE = '/^Source: (\S+)@([0-9a-f]{40}|[0-9a-f]{64})[ \t]*\r?$/m';

    private function __construct(
        public string $branch,
        public string $commit,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function in(string $text): ?self
    {
        $lines = self::linesIn($text);

        // Because the build gets that one commit, and picking one of two would decide for the author.
        if (count($lines) > 1) {
            throw new InvalidArgumentException('the task names more than one Source line, so the commit to build from is unclear');
        }

        $line = array_first($lines);

        return null === $line ? null : new self($line[1], $line[2]);
    }

    public static function isNamedIn(string $text): bool
    {
        return [] !== self::linesIn($text);
    }

    /**
     * @return list<array{string, string, string}>
     */
    private static function linesIn(string $text): array
    {
        preg_match_all(self::LINE, $text, $lines, PREG_SET_ORDER);

        return $lines;
    }
}
