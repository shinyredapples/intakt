<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;

// Queues a newer head right after a pending row is first read, as the listing of another worker would, so a keep that reads before it writes overwrites the newer head's due time.
final class NewerHeadOnFirstLoad
{
    private bool $queued = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $head,
        private readonly string $dueAt,
    ) {
    }

    public function postLoad(): void
    {
        if ($this->queued) {
            return;
        }

        $this->queued = true;
        $this->connection->executeStatement('UPDATE watcher_pending_head SET head = ?, read_again_at = ?', [$this->head, $this->dueAt]);
    }
}
