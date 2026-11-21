<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// A round the watcher queued that has not started, so it has a round id but no review and no review page yet.
final readonly class QueuedRound
{
    public function __construct(
        public string $round,
        public string $project,
        public string $change,
    ) {
    }

    // Named after the pull request, as the board names a watched head, because the watcher records the pull request's number as the change.
    public function title(): string
    {
        return 'Pull request '.$this->change;
    }
}
