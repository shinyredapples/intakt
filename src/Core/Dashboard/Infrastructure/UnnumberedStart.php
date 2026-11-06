<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\ReviewStarted;

// The review_started shape whose number is missing or null, because such rows were written before rounds were numbered and must keep reading back, while a number that is no integer still fails here and skips the row.
final readonly class UnnumberedStart
{
    public function __construct(
        public string $round,
        public string $project,
        public int $reviewers,
        public ?int $number = null,
        public string $change = '',
        public string $task = '',
        public ?string $repository = null,
        public ?string $base = null,
    ) {
    }

    // The placeholder number is never shown, because RoundPayloads gives this round none.
    public function asStarted(): ReviewStarted
    {
        return new ReviewStarted($this->round, $this->project, $this->reviewers, $this->change, 0, $this->task, $this->repository, $this->base);
    }
}
