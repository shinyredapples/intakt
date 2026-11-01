<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class ReviewSummary
{
    public function __construct(
        public string $round,
        public string $project,
        public int $reviewers,
        public DateTimeImmutable $startedAt,
        public ReviewState $state = ReviewState::InProgress,
    ) {
    }

    public function judged(): self
    {
        return clone($this, ['state' => ReviewState::Judged]);
    }
}
