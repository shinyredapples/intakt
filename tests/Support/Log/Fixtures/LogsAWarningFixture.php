<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\RecordingLogger;
use Intakt\Tests\Support\Log\RecordingLoggerFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class LogsAWarningFixture extends TestCase
{
    public function testItLogsOnlyAWarning(): void
    {
        $logger = RecordingLoggerFactory::create(new NullLogger());
        $logger->warning('Disk is warm.');

        self::assertInstanceOf(RecordingLogger::class, $logger);
    }
}
