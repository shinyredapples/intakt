<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// What the Trends page shows, read once, so the page and its pushed update count the same rounds in both ways of counting the demo.
final readonly class TrendsReading
{
    /**
     * @param list<ReviewedRound> $rounds
     */
    public function __construct(
        public array $rounds,
        public UpNext $upNext,
        public ?BenchHeadline $bench,
    ) {
    }

    public function counting(): ReviewTrends
    {
        return ReviewTrends::counting($this->rounds);
    }

    public function leavingOutTheDemo(): ReviewTrends
    {
        return ReviewTrends::leavingOutTheDemo($this->rounds);
    }
}
