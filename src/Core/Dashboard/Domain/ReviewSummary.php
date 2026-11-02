<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class ReviewSummary
{
    public string $round;
    public string $project;
    public int $reviewers;
    public ReviewState $state;

    public function __construct(
        RoundStart $start,
        public DateTimeImmutable $startedAt,
    ) {
        $this->state = ReviewState::InProgress;
        $this->round = $start->round;
        $this->project = $start->project;
        $this->reviewers = $start->reviewers;
    }

    public function judged(): self
    {
        return clone ($this, ['state' => ReviewState::Judged]);
    }
}
