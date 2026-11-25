<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\TypeRegistry;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;

// The parked-task table alone in memory, for tests that run a build without the install's database; with the install's time type, because the row stores its time with it.
final readonly class ParkedTaskTable
{
    public static function inMemory(): Connection
    {
        $types = new Configuration()->setTypeProvider(new TypeRegistry(['utc_datetime_immutable' => UtcDateTimeImmutableType::class]));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $types);
        $connection->executeStatement(sprintf('CREATE TABLE %s (project VARCHAR(255) NOT NULL, task INTEGER NOT NULL, question CLOB NOT NULL, choices CLOB NOT NULL, recommendation CLOB NOT NULL, parked_at DATETIME NOT NULL, PRIMARY KEY (project, task))', ParkedTaskRecord::TABLE));

        return $connection;
    }

    // A connection to a file that cannot be created, so every statement on it fails.
    public static function unopenable(string $folder): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $folder.'/missing/data.db']);
    }
}
