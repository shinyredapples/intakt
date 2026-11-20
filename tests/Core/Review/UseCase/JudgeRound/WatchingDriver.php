<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class WatchingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly WatchedStatements $watched)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new WatchingConnection(parent::connect($params), $this->watched);
    }
}
