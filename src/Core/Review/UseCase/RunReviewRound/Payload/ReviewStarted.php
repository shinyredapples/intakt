<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

// The payload of a ReviewStarted row; repository and base are left out of the JSON when the change has no source.
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
