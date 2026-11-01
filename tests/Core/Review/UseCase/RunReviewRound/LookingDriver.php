<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class LookingDriver extends AbstractDriverMiddleware
{
    /**
     * @param Closure(): mixed $meanwhile
     */
    public function __construct(Driver $driver, private readonly string $table, private readonly Closure $meanwhile)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new LookingConnection(parent::connect($params), $this->table, $this->meanwhile);
    }
}
