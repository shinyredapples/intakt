<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// A round the watcher queued for a pull request that no worker has started yet, so it has a round id and its review page but no review.
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
