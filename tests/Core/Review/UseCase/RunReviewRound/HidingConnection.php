<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Statement;

final class HidingConnection extends AbstractConnectionMiddleware
{
    private bool $looked = false;

    public function __construct(Connection $connection, private readonly string $table)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        if ($this->looked || !str_starts_with($sql, 'SELECT') || !str_contains($sql, ' '.$this->table.' ')) {
            return parent::prepare($sql);
        }

        $this->looked = true;

        return parent::prepare(preg_replace('/ WHERE /', ' WHERE 1 = 0 AND ', $sql, 1) ?? $sql);
    }
}
