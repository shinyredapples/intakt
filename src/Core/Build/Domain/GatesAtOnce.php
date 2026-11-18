<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use InvalidArgumentException;

// The most gates a builder's `bin/check` may run at once beside the other build slots, because three builders on 12 processors, each running eight gates at once, loaded the host to 22 to 28 and took 7 to 9 minutes a check (#1140).
final readonly class GatesAtOnce
{
    // Eight, because the measured checks ran eight gates at once (#1140), so this count never asks for more than they did.
    public const int MOST = 8;

    // Two, because one gate runs beside the `tests` gate even on a small host.
    public const int FEWEST = 2;

    public int $count;

    public function __construct(private int $processors, private int $slots)
    {
        if ($processors < 1 || $slots < 1) {
            throw new InvalidArgumentException(sprintf('The gates at once need at least one processor and one build slot, not %d and %d.', $processors, $slots));
        }

        $this->count = min(self::MOST, max(self::FEWEST, intdiv($processors, $slots)));
    }

    // Only ever a lower count, because `bin/check` takes what it is handed as it is: a count above the default it sizes from the job's CPUs and memory would run more gates than the job's memory holds. Null for a single build slot too, because one builder alone keeps that default (#2587, D2).
    public function loweringTheDefaultOfAJobWith(float $cpus, int $memoryBytes): ?int
    {
        $default = new GatesByDefault($this->processors, $cpus, $memoryBytes)->count;

        return $this->slots > 1 && $this->count < $default ? $this->count : null;
    }
}
