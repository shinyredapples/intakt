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

// The first end of an outage fails on its way to the database, on every engine, because that is the write a stored reading must not outlive; later ones pass, so the next reading can end the outage.
final readonly class FirstOutageEndFails implements Middleware
{
    public const string FAILURE = 'The end of the outage was not saved.';

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    private bool $failed = false;

                    public function prepare(string $sql): Statement
                    {
                        $this->failOnTheFirstEnd($sql);

                        return parent::prepare($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->failOnTheFirstEnd($sql);

                        return parent::exec($sql);
                    }

                    private function failOnTheFirstEnd(string $sql): void
                    {
                        if ($this->failed || !str_starts_with($sql, 'UPDATE budget_outage_notice ')) {
                            return;
                        }

                        $this->failed = true;

                        throw new RuntimeException(FirstOutageEndFails::FAILURE);
                    }
                };
            }
        };
    }
}
