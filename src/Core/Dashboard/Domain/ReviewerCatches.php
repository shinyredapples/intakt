<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Read from the recorded reporters alone, as the "One reviewer only" tile is, each under the severity its own reporter gave, so a round without a full record, or with a reported finding whose severity or reporter's role is unknown, is left out instead of guessed (D1 of #2858).
#[Exclude]
final readonly class ReviewerCatches
{
    /**
     * @param non-empty-list<ReviewerCatch> $reviewers
     */
    private function __construct(
        public array $reviewers,
    ) {
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    public static function of(array $rounds): ?self
    {
        $tallies = [];

        foreach (array_filter($rounds, self::isFullyRecorded(...)) as $round) {
            $tallies = self::tallied($round, $tallies);
        }

        if ([] === $tallies) {
            return null;
        }

        ksort($tallies);

        return new self(array_map(static fn (string $role, array $tally): ReviewerCatch => new ReviewerCatch($role, $tally['found'], $tally['alone']), array_keys($tallies), $tallies));
    }

    public function scaleTop(): int
    {
        return ChartScale::topFor(max(array_map(static fn (ReviewerCatch $reviewer): int => $reviewer->largest(), $this->reviewers)));
    }

    // Names every role at the top, because calling one of two equal reviewers "the most" would read as a lead it does not have.
    public function takeaway(): string
    {
        $most = max(array_map(static fn (ReviewerCatch $reviewer): int => $reviewer->found(Severity::P1), $this->reviewers));

        if (0 === $most) {
            return 'No reviewer found a P1 finding.';
        }

        $top = array_values(array_filter($this->reviewers, static fn (ReviewerCatch $reviewer): bool => $most === $reviewer->found(Severity::P1)));

        if (count($top) > 1) {
            return sprintf('The %s reviewers found the most P1 findings, %d each; %s.', $this->listed(array_map(static fn (ReviewerCatch $reviewer): string => $reviewer->role, $top)), $most, $this->listed(array_map($this->aloneInWords(...), $top)));
        }

        return sprintf('The %s reviewer found the most P1 findings, %d, and %d of them no other reviewer found.', $top[0]->role, $most, $top[0]->alone(Severity::P1));
    }

    /**
     * @param list<string> $parts
     */
    private function listed(array $parts): string
    {
        $last = array_pop($parts);

        return [] === $parts ? (string) $last : implode(', ', $parts).' and '.$last;
    }

    // Each tied reviewer keeps its own count of the P1s no other reviewer found, because equal totals can hide different exclusive catches (D3 of #2934).
    private function aloneInWords(ReviewerCatch $reviewer): string
    {
        return sprintf('the %s reviewer alone found %d', $reviewer->role, $reviewer->alone(Severity::P1));
    }

    // A reported severity that is not a `Severity` leaves the round out like a missing one, because the bars count only P1, P2 and P3 and would show that finding as none.
    private static function isFullyRecorded(ReviewedRound $round): bool
    {
        $reported = $round->findingsByReviewer;

        if (!$round->judgedAt instanceof DateTimeImmutable || !$round->findingTextRecorded || !$round->everyFindingRecorded || null === $reported || null === $round->severitiesByReviewer || [] !== array_diff_key($reported, $round->severitiesByReviewer)) {
            return false;
        }

        return array_all($reported, static fn (array $ids, string $reviewer): bool => null !== self::roleOf($round, $reviewer) && array_all($ids, static fn (string $id): bool => Severity::tryFrom($round->severityReportedBy($reviewer, $id) ?? '') instanceof Severity));
    }

    /**
     * @param array<string, array{found: array<string, int>, alone: array<string, int>}> $tallies
     *
     * @return array<string, array{found: array<string, int>, alone: array<string, int>}>
     */
    private static function tallied(ReviewedRound $round, array $tallies): array
    {
        foreach ($round->findingsByReviewer ?? [] as $reviewer => $ids) {
            $role = (string) self::roleOf($round, $reviewer);
            $tallies[$role] ??= ['found' => [], 'alone' => []];

            foreach (array_unique($ids) as $id) {
                $severity = (string) $round->severityReportedBy($reviewer, $id);
                $tallies[$role]['found'][$severity] = ($tallies[$role]['found'][$severity] ?? 0) + 1;
                $tallies[$role]['alone'][$severity] = ($tallies[$role]['alone'][$severity] ?? 0) + (self::isReportedByNoOtherReviewer($round, $reviewer, $id) ? 1 : 0);
            }
        }

        return $tallies;
    }

    private static function roleOf(ReviewedRound $round, string $reviewer): ?string
    {
        return array_find($round->outcomes, static fn (ReviewerOutcome $outcome): bool => $outcome->id === $reviewer)?->role;
    }

    // A finding an act entry merged stands with the entry's other findings, so a second reviewer who reported any of them shares the catch.
    private static function isReportedByNoOtherReviewer(ReviewedRound $round, string $reviewer, string $id): bool
    {
        $entry = array_find($round->findings, static fn (RoundFinding $finding): bool => in_array($id, $finding->ids(), true));
        $group = $entry?->ids() ?? [$id];
        $others = array_diff_key($round->findingsByReviewer ?? [], [$reviewer => true]);

        return array_all($others, static fn (array $ids): bool => [] === array_intersect($ids, $group));
    }
}
