<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

// The real size the worker holds from the system, which is what the container is charged for.
final readonly class PhpMemoryReading implements MemoryReading
{
    public function bytes(): int
    {
        return memory_get_usage(true);
    }
}
