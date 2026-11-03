<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// Status 0 is a normal end, so FrankenPHP starts a new worker and counts no failure toward max_consecutive_failures.
final readonly class CleanWorkerExit implements WorkerExit
{
    public function end(): void
    {
        exit(0);
    }
}
