<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Statement;

final class LookingConnection extends AbstractConnectionMiddleware
{
    private bool $looked = false;

    /**
     * @param Closure(): mixed $meanwhile
     */
    public function __construct(Connection $connection, private readonly string $table, private readonly Closure $meanwhile)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        if ($this->looked || !str_starts_with($sql, 'SELECT') || !str_contains($sql, ' '.$this->table.' ')) {
            return parent::prepare($sql);
        }

        $this->looked = true;

        return new LookingStatement(parent::prepare($sql), $this->meanwhile);
    }
}
