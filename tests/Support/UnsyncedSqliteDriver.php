<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Override;
use SensitiveParameter;

final class UnsyncedSqliteDriver extends AbstractDriverMiddleware
{
    /**
     * @param array<string, mixed> $params
     */
    #[Override]
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params);

        // Because the persistence tests on other engines share this container, and they refuse the pragma.
        if ($this->getDatabasePlatform($connection) instanceof SQLitePlatform) {
            $connection->exec('PRAGMA synchronous = OFF');
        }

        return $connection;
    }
}
