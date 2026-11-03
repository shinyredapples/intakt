<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(LogRecords::class)]
#[CoversClass(RecordingLogger::class)]
#[UsesClass(AssertsLoggedErrors::class)]
final class LogRecordsTest extends TestCase
{
    use AssertsLoggedErrors;

    protected function tearDown(): void
    {
        LogRecords::takeErrors();
    }

    public function testALoggedErrorTheTestAssertsIsRemovedFromTheRecord(): void
    {
        new RecordingLogger()->error('Disk on fire.');

        self::assertLoggedError(LogLevel::ERROR, 'Disk on fire.');

        self::assertSame([], LogRecords::takeErrors());
    }

    public function testAnErrorThatWasNotLoggedFailsTheAssertion(): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertLoggedError(LogLevel::ERROR, 'Disk on fire.');
    }

    public function testAnErrorWithAnotherMessageStaysInTheRecord(): void
    {
        new RecordingLogger()->error('Disk on fire.');

        self::assertFalse(LogRecords::remove(LogLevel::ERROR, 'Disk'));
        self::assertSame([['level' => LogLevel::ERROR, 'message' => 'Disk on fire.']], LogRecords::takeErrors());
    }

    public function testTheSourceLineOfAnUncaughtExceptionIsNotPartOfTheMessage(): void
    {
        new RecordingLogger()->error('Uncaught PHP Exception X: "boom" at Y.php line 12');

        self::assertLoggedError(LogLevel::ERROR, 'Uncaught PHP Exception X: "boom"');
    }

    public function testAWarningIsNotReportedAsAnError(): void
    {
        new RecordingLogger()->warning('Disk is warm.');

        self::assertSame([], LogRecords::takeErrors());
    }
}
