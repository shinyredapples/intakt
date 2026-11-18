<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use InvalidArgumentException;

// The gates a builder's `bin/check` runs at once, because three builders each running all 8 gates on 12 processors loaded the host to 22 to 28 and took 7 to 9 minutes a check (#1140).
final readonly class GatesAtOnce
{
    // Eight, because that is every gate of `bin/check --fast`, so one builder alone keeps running them all at once.
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
}
