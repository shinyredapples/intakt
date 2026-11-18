<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use InvalidArgumentException;

// The gates a builder's `bin/check` runs at once, because three builders each running all 8 gates on 12 processors loaded the host to 22 to 28 and took 7 to 9 minutes a check (#1140).
final readonly class GatesAtOnce
{
    // Eight, because the measured checks ran eight gates at once (#1140), so this count never asks for more than they did.
    public const int MOST = 8;

    // Two, because one gate runs beside the `tests` gate even on a small host.
    public const int FEWEST = 2;

    public int $count;

    public function __construct(int $processors, int $slots)
    {
        if ($processors < 1 || $slots < 1) {
            throw new InvalidArgumentException(sprintf('The gates at once need at least one processor and one build slot, not %d and %d.', $processors, $slots));
        }

        $this->count = min(self::MOST, max(self::FEWEST, intdiv($processors, $slots)));
    }

    // Null at or above the most a job's limits let `bin/check` pick by itself, because its own default is sized from the job's CPUs and memory and an override would only raise it.
    public function overridingBelow(int $jobsMost): ?int
    {
        return $this->count < $jobsMost ? $this->count : null;
    }
}
