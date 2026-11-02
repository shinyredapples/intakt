<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Its own value, because the project, the task and the diff name one change together; the project scopes the memory its reviewers are briefed with.
final readonly class ChangeUnderReview
{
    public function __construct(
        public string $project,
        public string $task,
        public string $diff,
    ) {
    }
}
