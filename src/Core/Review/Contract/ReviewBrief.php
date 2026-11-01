<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Its own value, because a reviewer is always told the task, the diff and the open findings together and the request stays at three parts; the project scopes the memory it is briefed with.
final readonly class ReviewBrief
{
    /**
     * @param list<Finding> $open
     */
    public function __construct(
        public string $project,
        public string $task,
        public string $diff,
        public array $open,
    ) {
    }
}
