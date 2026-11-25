<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use RuntimeException;
use SensitiveParameter;

// Any end of an outage sent after the driver committed fails, because a reading's end that runs once its reading is committed can fail with the reading already kept, leaving the notice with the later end it had.
final readonly class OutageEndAfterTheCommitFails implements Middleware
{
    public const string FAILURE = 'The end of the outage failed after the commit.';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    private bool $committed = false;

                    public function commit(): void
                    {
                        parent::commit();
                        $this->committed = true;
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->failOnAnEndAfterTheCommit($sql);

                        return parent::prepare($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->failOnAnEndAfterTheCommit($sql);

                        return parent::exec($sql);
                    }

                    private function failOnAnEndAfterTheCommit(string $sql): void
                    {
                        if (!$this->committed || !str_starts_with($sql, 'UPDATE budget_outage_notice ')) {
                            return;
                        }

                        throw new RuntimeException(OutageEndAfterTheCommitFails::FAILURE);
                    }
                };
            }
        };
    }
}
