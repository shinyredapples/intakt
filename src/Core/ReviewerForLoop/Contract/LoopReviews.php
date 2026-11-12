<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

final readonly class LoopReviews
{
    /**
     * @param list<LoopReview> $reviews
     */
    public function __construct(
        public array $reviews,
    ) {
    }
}
