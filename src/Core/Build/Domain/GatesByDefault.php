<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// The gates `bin/check` runs at once by itself inside a job with these limits. It states the check's own rule (`config/gates/src/GateSlots.php`) a second time, because the gates are development code the application cannot load; `GatesByDefaultTest` holds the two together.
final readonly class GatesByDefault
{
    // PHPStan's memory limit, because that is the share the check gives each gate and each test worker.
    public const int GATE_MEMORY_BYTES = 2 * 1024 ** 3;

    private const int MOST_TEST_WORKERS = 4;

    public int $count;

    public function __construct(int $processors, float $cpus, int $memoryBytes)
    {
        // Rounded up and never more than the host has, because that is how the check reads a job's CPU quota.
        $usable = max(1, min($processors, (int) ceil($cpus)));
        $testWorkers = min($usable, self::MOST_TEST_WORKERS);

        // The test workers hold their memory shares first, so only what they leave, plus the one gate that always runs, is the default.
        $this->count = max(1, min($usable, intdiv($memoryBytes, self::GATE_MEMORY_BYTES) - $testWorkers + 1));
    }
}
