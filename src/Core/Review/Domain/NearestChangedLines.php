<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Closure;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Finds, for each quote of a dropped finding, the changed lines whose names share the most word parts with the quote's names, because a dropped quote names nothing in the change, so only a part of a name (reasonForDrop against reasonToDrop) can show what it may have meant; a rule, not a service.
#[Exclude]
final readonly class NearestChangedLines
{
    // Bounds what each quote of a dropped finding adds to its ledger row, because the row is read on every dashboard page that shows the round.
    public const int MAX_LINES_PER_QUOTE = 3;
    public const int MAX_LINE_LENGTH = 160;
    // Split at an underscore, a digit and a change of case, because a quote may spell a name in another case style (fooBar, foo_bar, FOO_BAR).
    private const string WORD_PART = '/[A-Z]+(?![a-z])|[A-Z]?[a-z]+/';
    // Shorter parts are left out, because a part such as id, of or to is in too many names to say anything.
    private const int MIN_PART_LENGTH = 3;

    /**
     * @param Closure(string): string $redact
     */
    public function __construct(private Closure $redact)
    {
    }

    // Takes the changed lines redacted already, each within its whole file, because only the file shows where a credential of several lines starts.
    /**
     * @param list<array{string, int, string}> $changedLines
     *
     * @return list<QuoteNearLines>
     */
    public function forQuotesOf(string $findingText, array $changedLines): array
    {
        $changedLines = $this->withStorablePath($changedLines);
        // Without the redaction marker, because its word is put there by the redaction and names nothing in the change.
        $partsOfLines = array_map(fn (array $changed): array => $this->partsOf(str_replace(Redaction::MARKER, ' ', $changed[2])), $changedLines);
        $looked = [];
        $recorded = [];

        foreach (new QuotesInChange()->countedQuotesWithNames($findingText) as [$quote, $names]) {
            // Once per quote, because a quote the finding repeats would repeat its lines.
            if (isset($looked[$quote])) {
                continue;
            }

            $looked[$quote] = true;
            $closest = $this->closestTo($this->partsOf(implode(' ', $names)), $partsOfLines);
            $lines = array_map(fn (int $index): NearLine => new NearLine($changedLines[$index][0], $changedLines[$index][1], $this->cut($changedLines[$index][2])), array_slice($closest, 0, self::MAX_LINES_PER_QUOTE));

            // The quote is kept whole, because it is the key the dashboard finds the lines by and a cut one would equal another quote's key.
            if ([] !== $lines) {
                $recorded[] = new QuoteNearLines(mb_scrub(($this->redact)($quote), 'UTF-8'), $lines);
            }
        }

        return $recorded;
    }

    /**
     * @param list<array{string, int, string}> $changedLines
     *
     * @return list<array{string, int, string}>
     */
    private function withStorablePath(array $changedLines): array
    {
        $storable = [];

        foreach ($changedLines as [$path]) {
            // A path Git quoted into bytes that are not UTF-8 is left out, because the ledger row is JSON and a scrubbed path would name another file; so is a path the redaction changes, for the same reason.
            $storable[$path] ??= mb_check_encoding($path, 'UTF-8') && ($this->redact)($path) === $path;
        }

        return array_values(array_filter($changedLines, static fn (array $changed): bool => $storable[$changed[0]]));
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

    /**
     * @param list<string>       $parts
     * @param list<list<string>> $partsOfLines
     *
     * @return list<int>
     */
    private function closestTo(array $parts, array $partsOfLines): array
    {
        $scored = [];

        foreach ($partsOfLines as $index => $partsOfLine) {
            $shared = count(array_intersect($parts, $partsOfLine));

            // At least half of the quote's parts, because one common part such as "name" or "value" alone would make nearly every line close.
            if ($shared > 0 && 2 * $shared >= count($parts)) {
                $scored[] = [$shared, $index];
            }
        }

        // Stable, so lines that share as many parts keep their order in the diff.
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column($scored, 1);
    }

    // Takes redacted text: a credential cut in two no longer has the shape the redaction knows, and neither does one whose bytes were scrubbed. Scrubbed, because a diff line may hold bytes that are not UTF-8 and the ledger row is JSON.
    private function cut(string $redacted): string
    {
        return mb_substr(trim(mb_scrub($redacted, 'UTF-8')), 0, self::MAX_LINE_LENGTH, 'UTF-8');
    }
}
