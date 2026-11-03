<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\RecordingLoggerFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class LogsAnErrorAfterItsTestsFixture extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        RecordingLoggerFactory::create(new NullLogger())->error('Disk on fire after the tests.');
    }

    public function testItLogsNothingItself(): void
    {
        self::assertSame('test', 'test');
    }
}
