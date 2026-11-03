<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LogsAWarningFixture extends TestCase
{
    public function testItLogsOnlyAWarning(): void
    {
        $logger = new RecordingLogger();
        $logger->warning('Disk is warm.');

        self::assertInstanceOf(RecordingLogger::class, $logger);
    }
}
