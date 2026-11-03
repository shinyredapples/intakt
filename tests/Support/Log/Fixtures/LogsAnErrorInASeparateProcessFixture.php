<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\RecordingLogger;
use Intakt\Tests\Support\Log\RecordingLoggerFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class LogsAnErrorInASeparateProcessFixture extends TestCase
{
    #[RunInSeparateProcess]
    public function testItLogsAnErrorNobodyAssertsInItsOwnProcess(): void
    {
        $logger = RecordingLoggerFactory::create(new NullLogger());
        $logger->error('Disk on fire in isolation.');

        self::assertInstanceOf(RecordingLogger::class, $logger);
    }
}
