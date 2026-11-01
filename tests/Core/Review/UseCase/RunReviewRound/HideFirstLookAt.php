<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

// Forces the order in which a worker looked for a row before another worker stored it: the worker's first read of the table finds nothing, every later one reads the table as it is.
final readonly class HideFirstLookAt implements Middleware
{
    public function __construct(private string $table)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new HidingDriver($driver, $this->table);
    }
}
