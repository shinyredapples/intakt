<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobLimits
{
    public function __construct(
        public int $memoryBytes,
        public float $cpus,
        public int $seconds,
    ) {
        if ($memoryBytes <= 0 || $cpus <= 0.0 || $seconds <= 0) {
            throw JobRefused::forLimits();
        }
    }
}
