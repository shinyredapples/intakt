<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;

final readonly class TimeLimit
{
    private const int NANOSECONDS = 1_000_000_000;

    public function __construct(public int|float $seconds)
    {
        // Because a limit such as PHP_FLOAT_MAX is finite in seconds but infinite in nanoseconds, which would turn its deadline off.
        if (!is_finite($seconds * self::NANOSECONDS)) {
            throw new InvalidArgumentException(sprintf('A time limit must be a finite number of nanoseconds, not %s seconds.', var_export($seconds, true)));
        }
    }

    public function nanoseconds(): float
    {
        return $this->seconds * (float) self::NANOSECONDS;
    }

    public function label(): string
    {
        return $this->seconds.' seconds';
    }
}
