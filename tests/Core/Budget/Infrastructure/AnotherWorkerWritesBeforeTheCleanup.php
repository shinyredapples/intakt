<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

// Runs the other worker's write on the same connection just before the first cleanup, which is what a read-committed engine shows this worker when the other one commits between its insert and its cleanup; SQLite's single writer would otherwise keep that interleaving from happening.
final readonly class AnotherWorkerWritesBeforeTheCleanup implements Middleware
{
    public function __construct(private string $write)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->write) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $write)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->write) extends AbstractConnectionMiddleware {
                    private bool $written = false;

                    public function __construct(Connection $connection, private readonly string $write)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->writeBeforeTheCleanup($sql);

                        return parent::prepare($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->writeBeforeTheCleanup($sql);

                        return parent::exec($sql);
                    }

                    private function writeBeforeTheCleanup(string $sql): void
                    {
                        if ($this->written || !str_starts_with($sql, 'DELETE FROM budget_window_reading')) {
                            return;
                        }

                        $this->written = true;
                        parent::exec($this->write);
                    }
                };
            }
        };
    }
}
