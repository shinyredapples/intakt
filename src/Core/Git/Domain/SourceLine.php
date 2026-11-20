<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

final readonly class SourceLine
{
    // A whole line with a full commit id, so a sentence, a quote or code that mentions one is prose and not a source line.
    private const string LINE = '/^Source: (\S+)@([0-9a-f]{40}|[0-9a-f]{64})[ \t]*\r?$/';

    // No backtick after a backtick fence, because Markdown reads such a line as inline code and opens no fence.
    private const string FENCE_OPENS = '/^ {0,3}(`{3,}(?=[^`]*$)|~{3,})/';

    private const string FENCE_CLOSES = '/^ {0,3}(`{3,}|~{3,})[ \t]*\r?$/';

    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*\r?$/';

    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*\r?$/';

    private function __construct(
        public string $branch,
        public string $commit,
    ) {
    }

    /**
     * @throws SourceLineNotUnique
     */
    public static function in(string $text): ?self
    {
        $lines = self::linesIn($text);

        // Because the build gets that one commit, and picking one of two would decide for the author.
        if (count($lines) > 1) {
            throw SourceLineNotUnique::inTask();
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
        $external = false;

        foreach (explode("\n", $text) as $line) {
            // Because copied text in an external block is untrusted even in a trusted task (the threat model), the block hides every line, a fence too, until its closing marker or the end of the text, and that marker ends no fence.
            if ($external || 1 === preg_match(self::EXTERNAL_OPENS, $line)) {
                $external = 1 !== preg_match(self::EXTERNAL_CLOSES, $line);
                continue;
            }

            $fence = self::fenceAfter($fence, $line);

            if (null === $fence && 1 === preg_match(self::LINE, $line, $source)) {
                $lines[] = $source;
            }
        }

        return $lines;
    }

    private static function fenceAfter(?string $fence, string $line): ?string
    {
        // Because a code example shows the syntax and must not steer the clone, and an unclosed fence runs to the end of the text.
        if (null !== $fence) {
            return self::closes($fence, $line) ? null : $fence;
        }

        return 1 === preg_match(self::FENCE_OPENS, $line, $opening) ? $opening[1] : null;
    }

    private static function closes(string $fence, string $line): bool
    {
        return 1 === preg_match(self::FENCE_CLOSES, $line, $closing)
            && $closing[1][0] === $fence[0]
            && strlen($closing[1]) >= strlen($fence);
    }
}
