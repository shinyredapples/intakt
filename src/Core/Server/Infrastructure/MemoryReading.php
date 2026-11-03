<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

interface MemoryReading
{
    public function bytes(): int;
}
