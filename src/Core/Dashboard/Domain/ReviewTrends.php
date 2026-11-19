<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Budget\Contract\Cost;

// Each figure is null when the ledger holds nothing it could be read from, so the page shows "No data" instead of a zero nobody measured (D7 of #2858).
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

    // Null before any judge has ruled, because only a judged round has an act list.
    public function seriousActFindings(): ?int
    {
        $judged = $this->judgedRounds();

        if ([] === $judged) {
            return null;
        }

        return count(array_filter($this->actFindingsOf($judged), static fn (RoundFinding $finding): bool => in_array($finding->severity, self::SERIOUS, true)));
    }

    // An act entry that merged no other finding was reported once; a finding kept only as the judge's mark is left out, because without its record nobody can tell what it merged.
    public function caughtByOneReviewerOnlyPercent(): ?int
    {
        $recorded = array_filter($this->actFindingsOf($this->judgedRounds()), static fn (RoundFinding $finding): bool => null !== $finding->severity);

        if ([] === $recorded) {
            return null;
        }

        $alone = array_filter($recorded, static fn (RoundFinding $finding): bool => [] === $finding->merged);

        return (int) round(100 * count($alone) / count($recorded));
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

    /**
     * @return list<ReviewedRound>
     */
    private function judgedRounds(): array
    {
        return array_values(array_filter($this->rounds, static fn (ReviewedRound $round): bool => null !== $round->judgedAt));
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
