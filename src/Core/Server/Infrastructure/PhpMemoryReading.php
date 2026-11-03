<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// The true size is read because the container is charged for what the worker holds from the system, not for what PHP uses.
final readonly class PhpMemoryReading implements MemoryReading
{
    public function bytes(): int
    {
        return memory_get_usage(true);
    }
}
