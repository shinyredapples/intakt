<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028120000;
use Psr\Log\NullLogger;

// The test schema comes from the mapping, which has no triggers, so the migration adds them; on other platforms it adds nothing.
final readonly class AppendOnlyTriggers
{
    public static function in(Connection $connection): void
    {
        $migration = new Version20261028120000($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
