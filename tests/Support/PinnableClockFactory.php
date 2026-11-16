<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\LogRecords;
use Symfony\Component\Clock\ClockInterface;

final class PinnableClockFactory
{
    // Only a PHPUnit run has the run's log file, so an instance served from this checkout in the test environment keeps the real clock.
    public static function create(ClockInterface $real): ClockInterface
    {
        return LogRecords::ofThisRun() instanceof LogRecords ? new PinnableClock($real) : $real;
    }
}
