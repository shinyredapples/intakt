<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\ServerVersionProvider;

// The ORM renders a pessimistic write lock as nothing on SQLite, so a SQLite connection cannot show whether a read asked for one. This one names a server platform, which renders the lock as `FOR UPDATE`, records every statement as the ORM sent it and hands SQLite the statement without the clause it cannot run.
final class WriteLockOfAServer implements Middleware
{
    private const string WRITE_LOCK = ' FOR UPDATE';

    /** @var list<string> */
    public array $statements = [];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly WriteLockOfAServer $recorder)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                return new class(parent::connect($params), $this->recorder) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly WriteLockOfAServer $recorder)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare($this->recorder->ranOnSqlite($sql));
                    }

                    public function query(string $sql): Result
                    {
                        return parent::query($this->recorder->ranOnSqlite($sql));
                    }

                    public function exec(string $sql): int|string
                    {
                        return parent::exec($this->recorder->ranOnSqlite($sql));
                    }
                };
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return new MySQL80Platform();
            }
        };
    }

    public function ranOnSqlite(string $sql): string
    {
        $this->statements[] = $sql;

        return str_ends_with($sql, self::WRITE_LOCK) ? substr($sql, 0, -strlen(self::WRITE_LOCK)) : $sql;
    }

    /**
     * @return list<string>
     */
    public function writeLockedReadsOf(string $table): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => str_starts_with($sql, 'SELECT ') && str_contains($sql, ' FROM '.$table.' ') && str_ends_with($sql, self::WRITE_LOCK)));
    }
}
