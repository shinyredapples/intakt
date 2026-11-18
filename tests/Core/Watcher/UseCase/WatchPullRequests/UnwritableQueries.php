<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

// Refuses every query it is asked to build, as a database that is gone would, so a write through it fails on SQLite and PostgreSQL alike.
final class UnwritableQueries extends EntityManagerDecorator
{
    public function createQueryBuilder(): QueryBuilder
    {
        throw new RuntimeException('the pending head cannot be written');
    }
}
