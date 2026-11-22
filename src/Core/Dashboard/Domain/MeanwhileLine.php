<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

// A review running now, for the Waiting on you page's "Meanwhile": build lanes join it with plan T56 (#338), because the ledger holds only reviews' lanes yet.
final readonly class MeanwhileLine
{
    public function __construct(
        public string $text,
        public string $round,
        public string $project,
        private DateTimeImmutable $since,
    ) {
    }

    // A round from before the ledger named its change has no change, so the line says "A change", and its number falls back to the count of rounds, as the board's card does.
    public static function ofReview(Review $review): self
    {
        $latest = $review->latest();
        $change = '' === $review->change ? 'A change' : $review->change;

        return new self($change.' of '.$review->project.' is in review, round '.($latest->number ?? count($review->rounds)), $latest->round, $review->project, $latest->startedAt);
    }

    public function runningFor(DateTimeImmutable $now): string
    {
        return new Elapsed($this->since, $now)->inWords();
    }
}
