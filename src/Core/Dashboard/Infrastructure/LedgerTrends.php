<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\TrendsReading;
use Intakt\Core\Ledger\Contract\Ledger;
use Symfony\Component\Clock\ClockInterface;

// One home for the Trends reading, because the page and its live update must show the same figures and Up next for the same ledger.
final readonly class LedgerTrends
{
    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviews,
        private LedgerQueuedRounds $queued,
        private PublishedBench $bench,
        private ClockInterface $clock,
    ) {
    }

    // One read of the ledger and one set of reviews for the figures and Up next, so a round that starts meanwhile cannot show in one and not the other, and no round's replay mark is asked for twice.
    public function read(): TrendsReading
    {
        $events = $this->ledger->readInOrder();
        $reviews = $this->reviews->reviewsOf($events);

        return new TrendsReading(
            array_merge([], ...array_map(static fn (Review $review): array => $review->rounds, $reviews)),
            new Board($this->clock->now(), $reviews)->upNext($this->queued->in($events)),
            $this->bench->headline(),
        );
    }
}
