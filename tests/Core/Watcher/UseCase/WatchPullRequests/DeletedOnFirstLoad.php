<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;

// Deletes every row of the table right after the first row is read, as another worker settling it would, so a read that loads and then refreshes meets a row that is gone.
final class DeletedOnFirstLoad
{
    private bool $deleted = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    public function postLoad(): void
    {
        if ($this->deleted) {
            return;
        }

        $this->deleted = true;
        $this->connection->executeStatement('DELETE FROM '.$this->connection->quoteSingleIdentifier($this->table));
    }
}
