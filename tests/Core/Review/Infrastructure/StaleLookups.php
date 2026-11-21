<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;

// Finds nothing, as a lookup does that ran just before another writer committed the same row, so a test can force that order.
final class StaleLookups extends EntityManagerDecorator
{
    public function find(string $className, mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return null;
    }
}
