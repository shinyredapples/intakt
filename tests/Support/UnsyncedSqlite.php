<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Override;

// Because the test databases are scratch files that no test reads after a crash, and waiting for the disk on every statement made the SQLite tests several times slower than their work.
final readonly class UnsyncedSqlite implements Middleware
{
    #[Override]
    public function wrap(Driver $driver): Driver
    {
        return new UnsyncedSqliteDriver($driver);
    }
}
