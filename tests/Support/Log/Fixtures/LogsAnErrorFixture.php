<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LogsAnErrorFixture extends TestCase
{
    public function testItLogsAnErrorNobodyAsserts(): void
    {
        $logger = new RecordingLogger();
        $logger->error('Disk on fire.');

        self::assertInstanceOf(RecordingLogger::class, $logger);
    }
}
