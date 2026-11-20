<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

// Keeps every statement a worker sends and runs another worker right before the first one that starts as named, because that forces the order two workers meet in, which two threads in one test cannot.
final class WatchedStatements implements Middleware
{
    /** @var list<string> */
    public array $seen = [];

    private bool $met = false;

    /**
     * @param Closure(): mixed $meanwhile
     */
    public function __construct(private readonly string $before, private readonly Closure $meanwhile)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new WatchingDriver($driver, $this);
    }

    public function saw(string $sql): void
    {
        $this->seen[] = $sql;

        if (!$this->met && str_starts_with($sql, $this->before)) {
            $this->met = true;
            ($this->meanwhile)();
        }
    }
}
