<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

// Another writer runs right after the worker's first read of the table, because that forces the order in which it looked for a row before another worker committed it, which two threads in one test cannot.
final readonly class AfterFirstLookAt implements Middleware
{
    /**
     * @param Closure(): mixed $meanwhile
     */
    public function __construct(private string $table, private Closure $meanwhile)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new LookingDriver($driver, $this->table, $this->meanwhile);
    }
}
