<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A command for the next round of a change: it queues the round and returns its id without waiting for the judge, so a web request can send it.
final readonly class StartReview
{
    public function __construct(
        public string $project,
        public string $change,
        public string $task,
        public ChangeSource $source,
    ) {
    }
}
