<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A page's "In short" section, compared whole on both sides, so a changed line inside it, a removed or added section and a renamed heading all count (docs/design/05-architecture/07-reviews.md#the-technical-writer).
final readonly class SummarySection
{
    private const string HEADING = '/^ {0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/';

    private const string SUMMARY = 'in short';

    private const string FENCE_OPENS = '/^ {0,3}(`{3,}|~{3,})/';

    private const string FENCE_CLOSES = '/^ {0,3}(`{3,}|~{3,})[ \t]*$/';

    private const string SETEXT = '/^ {0,3}(=+|-+)[ \t]*$/';

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

    // Each line with its heading level, its title and whether it is paragraph text a setext underline can turn into a heading.
    /**
     * @return list<array{string, int, string, bool}>
     */
    private function linesOf(string $page): array
    {
        $split = preg_split('/\r?\n/', $page);
        $lines = [];
        $fence = null;

        foreach (false === $split ? [] : $split as $line) {
            // A heading inside a code block is code, so it never opens or closes the section.
            if (null !== $fence) {
                $fence = $this->closes($fence, $line) ? null : $fence;
                $lines[] = [$line, 0, '', false];
            } elseif (1 === preg_match(self::FENCE_OPENS, $line, $opening)) {
                $fence = $opening[1];
                $lines[] = [$line, 0, '', false];
            } elseif ([] !== $lines && array_last($lines)[3] && 1 === preg_match(self::SETEXT, $line, $underline)) {
                $text = array_pop($lines)[0];
                array_push($lines, [$text, '=' === $underline[1][0] ? 1 : 2, trim($text), false], [$line, 0, '', false]);
            } else {
                [$level, $title] = $this->headingOf($line);
                $lines[] = [$line, $level, $title, 0 === $level && '' !== trim($line)];
            }
        }

        return $lines;
    }

    // As CommonMark closes a fence: the same character, at least as many, and nothing after them but spaces.
    private function closes(string $fence, string $line): bool
    {
        return 1 === preg_match(self::FENCE_CLOSES, $line, $closing) && $closing[1][0] === $fence[0] && strlen($closing[1]) >= strlen($fence);
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
