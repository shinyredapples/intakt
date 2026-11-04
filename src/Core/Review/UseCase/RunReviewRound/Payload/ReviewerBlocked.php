<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

final readonly class ReviewerBlocked
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public string $tier,
        public string $reason,
    ) {
    }
}
