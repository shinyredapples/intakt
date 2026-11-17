<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewSummary;

final readonly class LedgerReviews
{
    public function __construct(
        private LedgerReviewPages $pages,
    ) {
    }

    // Read through the review pages' rounds, so the list shows the outcome and findings the review page decides and never decides them a second time.
    /**
     * @return list<ReviewSummary>
     */
    public function newestFirst(): array
    {
        return array_reverse(array_map(static fn (ReviewedRound $round): ReviewSummary => new ReviewSummary($round), $this->pages->listedRoundsInStartOrder()));
    }
}
