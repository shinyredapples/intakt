<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// Item n is the n-th top-level list item under the task's first Evidence heading, because the builder numbers them E1, E2, ... in that order whether or not the task writes the numbers.
final readonly class EvidenceItems
{
    private const string HEADING = '/^(?:#{1,6}[ \t]*)?(?:\*\*)?Evidence(?:\*\*)?[ \t]*:?[ \t]*(?:\*\*)?[ \t]*$/i';

    private const string TOP_LEVEL_ITEM = '/^(?:(?:[-*+]|\d{1,9}[.)])[ \t]+\S|E\d+[.:)])/';

    private const string SECTION_END = '/^(?:#{1,6}[ \t]|(?:\*\*)?[A-Za-z][A-Za-z \t]*:(?:\*\*)?[ \t]*$)/';

    private const string FENCE = '/^[ \t]{0,3}(?:```|~~~)/';

    public static function countIn(string $task): int
    {
        return self::outsideFences($task)
            |> self::evidenceSection(...)
            |> (static fn (array $section): int => count(array_filter($section, self::isTopLevelItem(...))));
    }

    /**
     * @return list<string>
     */
    private static function outsideFences(string $task): array
    {
        $lines = [];
        $fenced = false;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $task)) as $line) {
            if (1 === preg_match(self::FENCE, $line)) {
                $fenced = !$fenced;
            } elseif (!$fenced) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function evidenceSection(array $lines): array
    {
        $start = array_find_key($lines, static fn (string $line): bool => 1 === preg_match(self::HEADING, $line));

        if (null === $start) {
            return [];
        }

        $section = [];
        $previous = 'Evidence:';

        foreach (array_slice($lines, $start + 1) as $line) {
            if (self::endsTheSection($line, $previous)) {
                break;
            }

            $section[] = $line;
            $previous = $line;
        }

        return $section;
    }

    // Markdown reads a line at the start after a blank line as a new paragraph, so it ends the list, while one right after an item only continues it.
    private static function endsTheSection(string $line, string $previous): bool
    {
        if (1 === preg_match(self::SECTION_END, $line)) {
            return true;
        }

        return '' === trim($previous) && 1 === preg_match('/^\S/', $line) && !self::isTopLevelItem($line);
    }

    private static function isTopLevelItem(string $line): bool
    {
        return 1 === preg_match(self::TOP_LEVEL_ITEM, $line);
    }
}
