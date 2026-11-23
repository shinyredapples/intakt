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

// The second end of an outage fails on its way to the database, because a reading's second end runs after its commit, so the first end and the reading it saved are already kept.
final readonly class SecondOutageEndFails implements Middleware
{
    public const string FAILURE = 'The end of the outage was not moved after the commit.';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    private int $ends = 0;

                    public function prepare(string $sql): Statement
                    {
                        $this->failOnTheSecondEnd($sql);

                        return parent::prepare($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->failOnTheSecondEnd($sql);

                        return parent::exec($sql);
                    }

                    private function failOnTheSecondEnd(string $sql): void
                    {
                        if (!str_starts_with($sql, 'UPDATE budget_outage_notice ') || 2 !== ++$this->ends) {
                            return;
                        }

                        throw new RuntimeException(SecondOutageEndFails::FAILURE);
                    }
                };
            }
        };
    }
}
