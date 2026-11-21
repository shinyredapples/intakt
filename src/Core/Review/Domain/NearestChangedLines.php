<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Finds, for each quote of a dropped finding, the changed lines whose names share the most word parts with the quote's names, because a dropped quote names nothing in the change, so only a part of a name (reasonForDrop against reasonToDrop) can show what it may have meant; a rule, not a service.
#[Exclude]
final readonly class NearestChangedLines
{
    // Bounds what a dropped finding adds to its ledger row, because the row is read on every dashboard page that shows the round.
    public const int MAX_QUOTES = 5;
    public const int MAX_LINES_PER_QUOTE = 3;
    public const int MAX_LINE_LENGTH = 160;
    // Split at an underscore, a digit and a change of case, so fooBar, foo_bar and FOO_BAR give the same parts.
    private const string WORD_PART = '/[A-Z]+(?![a-z])|[A-Z]?[a-z]+/';
    // Shorter parts (id, of, to) are in too many names to say anything.
    private const int MIN_PART_LENGTH = 3;

    /**
     * @param list<array{string, int, string}> $changedLines file, line number in the changed file, text
     *
     * @return list<QuoteNearLines>
     */
    public function forQuotesOf(string $findingText, array $changedLines): array
    {
        $partsOfLines = array_map(fn (array $changed): array => $this->partsOf($changed[2]), $changedLines);
        $recorded = [];

        foreach (new QuotesInChange()->countedQuotesWithNames($findingText) as [$quote, $names]) {
            $lines = $this->nearestTo($this->partsOf(implode(' ', $names)), $changedLines, $partsOfLines);

            if ([] !== $lines) {
                $recorded[] = new QuoteNearLines($this->cut($quote), $lines);
            }
        }

        return array_slice($recorded, 0, self::MAX_QUOTES);
    }

    /**
     * @param list<string>                     $parts
     * @param list<array{string, int, string}> $changedLines
     * @param list<list<string>>               $partsOfLines
     *
     * @return list<NearLine>
     */
    private function nearestTo(array $parts, array $changedLines, array $partsOfLines): array
    {
        $scored = [];

        foreach ($changedLines as $index => [$file, $line, $text]) {
            $shared = count(array_intersect($parts, $partsOfLines[$index]));

            // At least half of the quote's parts, because one common part such as "name" or "value" alone would make nearly every line close.
            if ($shared > 0 && 2 * $shared >= count($parts)) {
                $scored[] = [$shared, new NearLine($file, $line, $this->cut($text))];
            }
        }

        // Stable, so lines that share as many parts keep their order in the diff.
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_slice(array_column($scored, 1), 0, self::MAX_LINES_PER_QUOTE);
    }

    /**
     * @return list<string>
     */
    private function partsOf(string $text): array
    {
        preg_match_all(self::WORD_PART, $text, $found);
        $parts = array_map(strtolower(...), $found[0]);

        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => strlen($part) >= self::MIN_PART_LENGTH)));
    }

    // Scrubbed, because a diff line may hold bytes that are not UTF-8, and the ledger row is JSON.
    private function cut(string $text): string
    {
        return mb_substr(mb_scrub(trim($text), 'UTF-8'), 0, self::MAX_LINE_LENGTH, 'UTF-8');
    }
}
