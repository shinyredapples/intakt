<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Only rounds the ledger has started, because each entry links to its review page and a round the watcher queued has none yet.
final readonly class UpNext
{
    public const string NOTHING_PLANNED = 'Nothing running or queued.';

    /** @var list<ReviewedRound> */
    public array $inProgress;

    /** @var list<ReviewedRound> */
    public array $waitingToStart;

    // A round counts as in progress from its first reviewer's start, so a round whose reviewers all answered stays listed until the judge decides it.
    /**
     * @param list<Review> $reviews in the board's order
     */
    public function __construct(array $reviews)
    {
        $active = array_values(array_filter(array_map(static fn (Review $review): ?ReviewedRound => $review->activeRound(), $reviews), static fn (?ReviewedRound $round): bool => $round instanceof ReviewedRound));
        $this->inProgress = array_values(array_filter($active, static fn (ReviewedRound $round): bool => [] !== $round->outcomes));
        $this->waitingToStart = array_values(array_filter($active, static fn (ReviewedRound $round): bool => [] === $round->outcomes));
    }

    public function emptyLine(): ?string
    {
        return [] === $this->inProgress && [] === $this->waitingToStart ? self::NOTHING_PLANNED : null;
    }
}
