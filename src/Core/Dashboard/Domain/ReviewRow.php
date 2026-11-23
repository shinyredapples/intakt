<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class ReviewRow
{
    public function __construct(
        public ReviewSummary $review,
        private DateTimeImmutable $now,
    ) {
    }

    public function startedAgo(): string
    {
        return Elapsed::ago($this->now->getTimestamp() - $this->review->startedAt->getTimestamp());
    }
}
