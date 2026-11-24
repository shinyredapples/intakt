<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

// Each statement and each commit of a worker passes through the test's hooks, because SQLite locks the file for a whole write transaction, so an order a read-committed engine allows is forced here instead.
final readonly class ForcedOrder implements Middleware
{
    /**
     * @param Closure(string): string $statement
     * @param Closure(): void         $beforeCommit
     */
    public function __construct(
        private Closure $statement,
        private Closure $beforeCommit = static function (): void {},
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->statement, $this->beforeCommit) extends AbstractDriverMiddleware {
            /**
             * @param Closure(string): string $statement
             * @param Closure(): void         $beforeCommit
             */
            public function __construct(Driver $driver, private readonly Closure $statement, private readonly Closure $beforeCommit)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->statement, $this->beforeCommit) extends AbstractConnectionMiddleware {
                    /**
                     * @param Closure(string): string $statement
                     * @param Closure(): void         $beforeCommit
                     */
                    public function __construct(Connection $connection, private readonly Closure $statement, private readonly Closure $beforeCommit)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare(($this->statement)($sql));
                    }

                    public function query(string $sql): Result
                    {
                        return parent::query(($this->statement)($sql));
                    }

                    public function exec(string $sql): int|string
                    {
                        return parent::exec(($this->statement)($sql));
                    }

                    public function commit(): void
                    {
                        ($this->beforeCommit)();
                        parent::commit();
                    }
                };
            }
        };
    }
}
