<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\AssertsLoggedErrors;
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
        RecordingLoggerFactory::create(new NullLogger())->error('Disk on fire with the trait.');

        self::assertTrue(true);
    }
}
