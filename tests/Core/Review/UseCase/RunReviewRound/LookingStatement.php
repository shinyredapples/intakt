<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class LookingStatement extends AbstractStatementMiddleware
{
    /**
     * @param Closure(): mixed $meanwhile
     */
    public function __construct(Statement $statement, private readonly Closure $meanwhile)
    {
        parent::__construct($statement);
    }

    // After the read, because only then has the worker's transaction taken the snapshot the other writer's commit makes stale.
    public function execute(): Result
    {
        $result = parent::execute();
        ($this->meanwhile)();

        return $result;
    }
}
