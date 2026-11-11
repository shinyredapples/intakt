<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobLimits
{
    private const float NANO = 1e9;

    // Checked in nanoCPUs, the unit the engine takes, because a value that rounds to zero there turns the CPU limit off.
    public int $nanoCpus;

    public function __construct(
        public int $memoryBytes,
        public float $cpus,
        public int $seconds,
    ) {
        $nanoCpus = round($cpus * self::NANO);

        if ($memoryBytes <= 0 || !is_finite($nanoCpus) || $nanoCpus < 1.0 || $nanoCpus >= (float) PHP_INT_MAX || $seconds <= 0) {
            throw JobRefused::forLimits();
        }

        $this->nanoCpus = (int) $nanoCpus;
    }
}
