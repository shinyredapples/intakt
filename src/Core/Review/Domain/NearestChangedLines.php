<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Closure;
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

    /**
     * @param list<array{0: string, 1: int, 2: string, 3?: string, 4?: int}> $changedLines
     *
     * @return list<QuoteNearLines>
     */
    public function forQuotesOf(string $findingText, array $changedLines): array
    {
        $changedLines = $this->withStorablePath($changedLines);
        $partsOfLines = array_map(fn (array $changed): array => $this->partsOf($changed[2]), $changedLines);
        $redactedHunks = [];
        $recorded = [];

        foreach (new QuotesInChange()->countedQuotesWithNames($findingText) as [$quote, $names]) {
            $closest = $this->closestTo($this->partsOf(implode(' ', $names)), $partsOfLines);
            $lines = $this->storedOf(array_map(static fn (int $index): array => $changedLines[$index], $closest), $redactedHunks);

            if ([] !== $lines) {
                $recorded[] = new QuoteNearLines($this->cut(($this->redact)($quote)), $lines);
            }
        }

        return $recorded;
    }

    /**
     * @param list<array{0: string, 1: int, 2: string, 3?: string, 4?: int}> $changedLines
     *
     * @return list<array{0: string, 1: int, 2: string, 3?: string, 4?: int}>
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

    /**
     * @param list<array{0: string, 1: int, 2: string, 3?: string, 4?: int}> $closest
     * @param array<list<string>>                                            $redactedHunks
     *
     * @return list<NearLine>
     */
    private function storedOf(array $closest, array &$redactedHunks): array
    {
        $stored = [];

        foreach ($closest as $changed) {
            // The line alone when it comes without its hunk, because there is then no other line to redact it with.
            $hunk = $changed[3] ?? $changed[2];
            $redactedHunks[$hunk] ??= $this->redactedLinesOf($hunk);
            $text = $redactedHunks[$hunk][$changed[4] ?? 0] ?? null;

            // Not counted, because a line left out with its hunk would take the place of one that can be shown.
            if (null !== $text) {
                $stored[] = new NearLine($changed[0], $changed[1], $this->cut($text));
            }

            if (self::MAX_LINES_PER_QUOTE === count($stored)) {
                break;
            }
        }

        return $stored;
    }

    // Redacted as one text, because a private key is known only by its first line. No line when the redaction took a line break away, because the result's lines are then no longer the hunk's.
    /**
     * @return list<string>
     */
    private function redactedLinesOf(string $hunk): array
    {
        $redacted = explode("\n", ($this->redact)($hunk));

        return count($redacted) === substr_count($hunk, "\n") + 1 ? $redacted : [];
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

    // Takes redacted text: a credential cut in two no longer has the shape the redaction knows, and neither does one whose bytes were scrubbed. Scrubbed, because a diff line may hold bytes that are not UTF-8 and the ledger row is JSON.
    private function cut(string $redacted): string
    {
        return mb_substr(trim(mb_scrub($redacted, 'UTF-8')), 0, self::MAX_LINE_LENGTH, 'UTF-8');
    }
}
