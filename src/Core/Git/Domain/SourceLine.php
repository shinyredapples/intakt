<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use InvalidArgumentException;

final readonly class SourceLine
{
    // A whole line with a full commit id, so a sentence, a quote or code that mentions one is prose and not a source line.
    private const string LINE = '/^Source: (\S+)@([0-9a-f]{40}|[0-9a-f]{64})[ \t]*\r?$/';

    // Markdown's fence: a backtick fence's info string holds no backtick, or the line is inline code.
    private const string FENCE_OPENS = '/^ {0,3}(`{3,}(?=[^`]*$)|~{3,})/';

    private const string FENCE_CLOSES = '/^ {0,3}(`{3,}|~{3,})[ \t]*\r?$/';

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
        $lines = [];
        $fence = null;

        foreach (explode("\n", $text) as $line) {
            // Because a code example shows the syntax and must not steer the clone, and an unclosed fence runs to the end of the text.
            if (null !== $fence) {
                $fence = self::closes($fence, $line) ? null : $fence;
            } elseif (1 === preg_match(self::FENCE_OPENS, $line, $opening)) {
                $fence = $opening[1];
            } elseif (1 === preg_match(self::LINE, $line, $source)) {
                $lines[] = $source;
            }
        }

        return $lines;
    }

    private static function closes(string $fence, string $line): bool
    {
        return 1 === preg_match(self::FENCE_CLOSES, $line, $closing)
            && $closing[1][0] === $fence[0]
            && strlen($closing[1]) >= strlen($fence);
    }
}
