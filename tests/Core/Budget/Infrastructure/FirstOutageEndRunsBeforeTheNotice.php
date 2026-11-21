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

// The first end of an outage matches no row, as on a read-committed engine when it runs before a notice writer commits its row, and that writer's re-check runs before this reading commits; SQLite locks the file for the whole transaction, so the order is forced here instead.
final readonly class FirstOutageEndRunsBeforeTheNotice implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    private bool $ran = false;

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare($this->beforeTheNotice($sql));
                    }

                    public function exec(string $sql): int|string
                    {
                        return parent::exec($this->beforeTheNotice($sql));
                    }

                    private function beforeTheNotice(string $sql): string
                    {
                        if ($this->ran || !str_starts_with($sql, 'UPDATE budget_outage_notice ') || !str_contains($sql, ' WHERE ')) {
                            return $sql;
                        }

                        $this->ran = true;

                        return str_replace(' WHERE ', ' WHERE 1 = 0 AND (', $sql).')';
                    }
                };
            }
        };
    }
}
