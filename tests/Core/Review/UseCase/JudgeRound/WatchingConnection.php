<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class WatchingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly WatchedStatements $watched)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->watched->saw($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->watched->saw($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->watched->saw($sql);

        return parent::exec($sql);
    }
}
