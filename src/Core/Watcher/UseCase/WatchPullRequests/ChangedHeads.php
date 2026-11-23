<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\DBAL\Connection;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class ChangedHeads
{
    public function __construct(
        private FindChangedPullRequests $find,
        private PendingHeads $pending,
        private WatcherLedger $ledger,
        private Connection $connection,
        private ChangesHandedToIntakt $handedToIntakt,
    ) {
    }

    // One transaction with the listing's record, because a listing recorded as seen names those pull requests never again, so they must be recognized and queued or the record undone; committed under the listing's lock, because an overlapping tick would otherwise read the ledger before this one's handed-to-Intakt lines and append them again.
    /**
     * @throws ListingInProgress
     * @throws ForgeRateLimited
     */
    public function enqueue(string $project): void
    {
        $this->find->whileListing($project, fn (Closure $listChanged) => $this->connection->transactional(function () use ($project, $listChanged): void {
            $changed = $listChanged();
            // Every open pull request, not only the changed ones, because one listed before its opening record was written may never change again.
            $this->handedToIntakt->recognizeAmong($project, $this->find->openOf($project));
            $this->pending->enqueue($project, $changed);
            $this->recordForgotten($project, $this->pending->forgetClosed($project));
        }));
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
