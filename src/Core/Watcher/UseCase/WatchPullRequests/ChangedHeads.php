<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class ChangedHeads
{
    public function __construct(
        private FindChangedPullRequests $find,
        private PendingHeads $pending,
        private WatcherLedger $ledger,
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
            $this->recordForgotten($project, $this->pending->forgetClosed($project));
        });
    }

    // Recorded inside that transaction, so a line the ledger refuses brings the rows and the listing's record back, and the next tick forgets them again; without the line, the card of a failed head would promise a try that never comes.
    /**
     * @param list<OpenChange> $closed
     */
    private function recordForgotten(string $project, array $closed): void
    {
        foreach ($closed as $head) {
            $this->ledger->headForgotten($project, $head);
        }
    }
}
