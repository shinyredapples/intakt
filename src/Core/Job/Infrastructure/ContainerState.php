<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

final readonly class ContainerState
{
    public function __construct(
        public bool $running,
        public bool $memoryKilled,
        public int $exitCode,
    ) {
    }
}
