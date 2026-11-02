<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;

final readonly class ChangedHeads
{
    public function __construct(
        private FindChangedPullRequests $find,
        private PendingHeads $pending,
        private Connection $connection,
    ) {
    }

    // One transaction with the listing's record, because a listing recorded as seen names those pull requests never again, so they must be queued or the record undone.
    /**
     * @throws ListingInProgress
     */
    public function enqueue(string $project): void
    {
        $this->connection->transactional(function () use ($project): void {
            $this->pending->enqueue($project, ($this->find)($project));
            $this->pending->forgetClosed($project);
        });
    }
}
