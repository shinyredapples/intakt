<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

// A null repository and base are left out of the JSON because rows of a change without a source never had the keys.
final readonly class ReviewStarted
{
    public function __construct(
        public string $round,
        public string $project,
        public int $reviewers,
        public string $change,
        public int $number,
        public string $task,
        public ?string $repository = null,
        public ?string $base = null,
    ) {
    }
}
