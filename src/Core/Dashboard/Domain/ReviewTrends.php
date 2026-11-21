<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Each figure is null when the ledger holds nothing it could be read from, so the page shows "No data" instead of a zero nobody measured (D7 of #2858).
#[Exclude]
final readonly class ReviewTrends
{
    private const array SERIOUS = ['P1', 'P2'];

    // The demo mark counts the ledger's replayed rounds whether shown or left out, so the chip can bring them back.
    /**
     * @param list<ReviewedRound> $rounds
     */
    private function __construct(
        public array $rounds,
        private bool $anyDemo,
        public bool $demoLeftOut,
        public bool $anyReview,
    ) {
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    public static function counting(array $rounds): self
    {
        return new self($rounds, array_any($rounds, static fn (ReviewedRound $round): bool => $round->isReplay()), false, [] !== $rounds);
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    public static function leavingOutTheDemo(array $rounds): self
    {
        $live = array_values(array_filter($rounds, static fn (ReviewedRound $round): bool => !$round->isReplay()));

        return new self($live, count($live) !== count($rounds), true, [] !== $rounds);
    }

    public function includesTheDemo(): bool
    {
        return $this->anyDemo && !$this->demoLeftOut;
    }

    public function canLeaveOutTheDemo(): bool
    {
        return $this->anyDemo;
    }

    public function roundsRun(): int
    {
        return count($this->rounds);
    }

    // Counted over the judged rounds whose every act entry has a recorded severity, because an entry kept only as the judge's mark may have been a P1, and a count that skipped it would read as measured; null when no such round is left.
    public function seriousActFindings(): ?int
    {
        $known = array_values(array_filter($this->judgedRounds(), static fn (ReviewedRound $round): bool => array_all($round->actFindings(), static fn (RoundFinding $finding): bool => null !== $finding->severity)));

        if ([] === $known) {
            return null;
        }

        return count(array_filter($this->actFindingsOf($known), static fn (RoundFinding $finding): bool => in_array($finding->severity, self::SERIOUS, true)));
    }

    // Read from the recorded reporters alone, because the judge also merges two findings of one reviewer at one place, so a merged entry proves no second reviewer, and D1 of #2858 rules out an estimate; rounds without the record and entries no recorded reviewer reported are left out, and null when no entry is left.
    public function caughtByOneReviewerOnlyPercent(): ?int
    {
        $reporters = array_merge([], ...array_map($this->reportersPerActEntry(...), $this->judgedRounds()));
        $known = array_values(array_filter($reporters, static fn (int $count): bool => $count > 0));

        if ([] === $known) {
            return null;
        }

        return (int) round(100 * count(array_filter($known, static fn (int $count): bool => 1 === $count)) / count($known));
    }

    public function medianSecondsToVerdict(): ?int
    {
        $seconds = array_map(static fn (ReviewedRound $round): int => ($round->judgedAt?->getTimestamp() ?? $round->startedAt->getTimestamp()) - $round->startedAt->getTimestamp(), $this->judgedRounds());

        if ([] === $seconds) {
            return null;
        }

        sort($seconds);
        $middle = intdiv(count($seconds), 2);

        return 0 === count($seconds) % 2 ? intdiv($seconds[$middle - 1] + $seconds[$middle], 2) : $seconds[$middle];
    }

    public function medianTimeToVerdict(): ?string
    {
        $seconds = $this->medianSecondsToVerdict();

        return null === $seconds ? null : $this->inWords($seconds);
    }

    // The mean of the rounds whose estimate is known and that made a call, because a round without a call has spent nothing yet and an unpriced one has no estimate to add.
    public function costPerRound(): ?Cost
    {
        $priced = array_values(array_filter($this->rounds, static fn (ReviewedRound $round): bool => null !== $round->cost->usd && $round->tokens->total() > 0));

        if ([] === $priced) {
            return null;
        }

        $total = array_reduce($priced, static fn (Cost $sum, ReviewedRound $round): Cost => $sum->plus($round->cost), Cost::none());

        return Cost::inUsd(($total->usd ?? 0.0) / count($priced), array_last($priced)->cost->priceDate);
    }

    public function reviewerCatches(): ?ReviewerCatches
    {
        return ReviewerCatches::of($this->rounds);
    }

    public function judgeOutcomes(): ?JudgeOutcomes
    {
        return JudgeOutcomes::of($this->rounds);
    }

    public function reviewsPerDay(): ?ReviewsPerDay
    {
        return ReviewsPerDay::of($this->rounds);
    }

    /**
     * @return list<ReviewedRound>
     */
    private function judgedRounds(): array
    {
        return array_values(array_filter($this->rounds, static fn (ReviewedRound $round): bool => $round->judgedAt instanceof DateTimeImmutable));
    }

    // An act entry stands for its own finding and the ones it merged, so each reviewer that reported any of them counts once.
    /**
     * @return list<int>
     */
    private function reportersPerActEntry(ReviewedRound $round): array
    {
        $reported = $round->findingsByReviewer;

        if (null === $reported) {
            return [];
        }

        return array_map(static fn (RoundFinding $entry): int => count(array_filter($reported, static fn (array $ids): bool => [] !== array_intersect($ids, [$entry->id, ...$entry->merged]))), $round->actFindings());
    }

    /**
     * @param list<ReviewedRound> $rounds
     *
     * @return list<RoundFinding>
     */
    private function actFindingsOf(array $rounds): array
    {
        return array_merge([], ...array_map(static fn (ReviewedRound $round): array => $round->actFindings(), $rounds));
    }

    private function inWords(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.' s',
            $seconds < 3600 => intdiv($seconds, 60).' min '.($seconds % 60).' s',
            default => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
        };
    }
}
