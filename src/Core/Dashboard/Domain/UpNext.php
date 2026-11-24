<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Queued rounds come apart from the started ones, because a round has no review page until it starts, so the page lists it without a link.
final readonly class UpNext
{
    public const string NOTHING_PLANNED = 'Nothing running or queued.';

    /** @var list<ReviewedRound> */
    public array $inProgress;

    /** @var list<ReviewedRound> */
    public array $waitingToStart;

    // A round counts as in progress from its first recorded reviewer start, because a reviewer blocked before it started never ran.
    /**
     * @param list<Review>      $reviews
     * @param list<QueuedRound> $queued
     */
    public function __construct(array $reviews, public array $queued)
    {
        $active = array_values(array_filter(array_map(static fn (Review $review): ?ReviewedRound => $review->activeRound(), $reviews), static fn (?ReviewedRound $round): bool => $round instanceof ReviewedRound));
        $this->inProgress = array_values(array_filter($active, static fn (ReviewedRound $round): bool => [] !== $round->startedOutcomes()));
        $this->waitingToStart = array_values(array_filter($active, static fn (ReviewedRound $round): bool => [] === $round->startedOutcomes()));
    }

    public function isWaiting(): bool
    {
        return [] !== $this->waitingToStart || [] !== $this->queued;
    }

    public function emptyLine(): ?string
    {
        return [] === $this->inProgress && !$this->isWaiting() ? self::NOTHING_PLANNED : null;
    }
}
