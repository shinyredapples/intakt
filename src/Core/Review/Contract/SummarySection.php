<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A page's "In short" section, compared whole on both sides, so a changed line inside it, a removed or added section and a renamed heading all count (docs/design/05-architecture/07-reviews.md#the-technical-writer).
final readonly class SummarySection
{
    private const string HEADING = '/^ {0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/';

    private const string SUMMARY = 'in short';

    private const string FENCE = '/^ {0,3}(```|~~~)/';

    // An absent page is an empty one, so an added or deleted page with a summary counts as touching it.
    public function changedBetween(string $before, string $after): bool
    {
        return $this->of($before) !== $this->of($after);
    }

    // From its heading up to the next heading of the same or a higher level, because a deeper heading is part of the section.
    private function of(string $page): ?string
    {
        $lines = $this->linesOf($page);
        $start = array_find_key($lines, static fn (array $line): bool => 0 < $line[1] && self::SUMMARY === mb_strtolower($line[2]));

        if (null === $start) {
            return null;
        }

        $level = $lines[$start][1];
        $end = array_find_key(array_slice($lines, $start + 1), static fn (array $line): bool => 0 < $line[1] && $line[1] <= $level);

        return implode("\n", array_column(array_slice($lines, $start, null === $end ? null : $end + 1), 0));
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function linesOf(string $page): array
    {
        $split = preg_split('/\r?\n/', $page);
        $lines = [];
        $fenced = false;

        foreach (false === $split ? [] : $split as $line) {
            // A "#" line inside a code block is code, so it never opens or closes the section.
            $fenced = 1 === preg_match(self::FENCE, $line) ? !$fenced : $fenced;
            [$level, $title] = $fenced ? [0, ''] : $this->headingOf($line);
            $lines[] = [$line, $level, $title];
        }

        return $lines;
    }

    // Level 0 for a line that is no heading, so callers compare levels without a null check.
    /**
     * @return array{int, string}
     */
    private function headingOf(string $line): array
    {
        return 1 === preg_match(self::HEADING, $line, $found) ? [strlen($found[1]), $found[2]] : [0, ''];
    }
}
