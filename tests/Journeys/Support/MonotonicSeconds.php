<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// The system's monotonic clock in seconds, because the journey compares moments that several processes of one machine wrote, and a clock that the wall time can move would break that order.
final class MonotonicSeconds
{
    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
