<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

// The worker's first read of the table finds nothing, because that forces the order in which it looked for a row before another worker stored it, which two threads in one test cannot.
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
