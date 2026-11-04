<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\RecordingLogger;
use Intakt\Tests\Support\Log\RecordingLoggerFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class LogsAnErrorWithTheTraitFixture extends TestCase
{
    use AssertsLoggedErrors;

    public function testItLogsAnErrorNobodyAssertsAndFailsItself(): void
    {
        $logger = RecordingLoggerFactory::create(new NullLogger());
        $logger->error('Disk on fire with the trait.');

        self::assertInstanceOf(RecordingLogger::class, $logger);
    }
}
