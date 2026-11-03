<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Psr\Log\LoggerInterface;

final class RecordingLoggerFactory
{
    // Only a PHPUnit run has records, so an instance served from this checkout in the test environment keeps its stderr log.
    public static function create(LoggerInterface $fallback): LoggerInterface
    {
        $records = LogRecords::ofThisRun();

        return null === $records ? $fallback : new RecordingLogger($records);
    }
}
