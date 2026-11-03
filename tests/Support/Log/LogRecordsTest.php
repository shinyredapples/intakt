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

    private LogRecords $records;

    protected function setUp(): void
    {
        $this->records = new LogRecords((string) tempnam(sys_get_temp_dir(), 'intakt-log-records-test-'));
    }

    protected function tearDown(): void
    {
        $this->records->discard();
    }

    public function testALoggedErrorTheTestAssertsIsRemovedFromTheRecord(): void
    {
        $this->runLogger()->error('Disk on fire.');

        self::assertTrue($this->records->remove(LogLevel::ERROR, 'Disk on fire.'));

        self::assertSame([], $this->records->takeErrors());
    }

    public function testAnErrorThatWasNotLoggedFailsTheAssertion(): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertLoggedError(LogLevel::ERROR, 'Disk on fire, never logged.');
    }

    public function testAnErrorWithAnotherMessageStaysInTheRecord(): void
    {
        $this->runLogger()->error('Disk on fire.');

        self::assertFalse($this->records->remove(LogLevel::ERROR, 'Disk'));
        self::assertSame([['level' => LogLevel::ERROR, 'message' => 'Disk on fire.']], $this->records->takeErrors());
    }

    public function testTheSourceLineOfAnUncaughtExceptionIsNotPartOfTheMessage(): void
    {
        $this->runLogger()->error('Uncaught PHP Exception X: "boom" at Y.php line 12');

        self::assertTrue($this->records->remove(LogLevel::ERROR, 'Uncaught PHP Exception X: "boom"'));
    }

    public function testAWarningIsNotReportedAsAnError(): void
    {
        $this->runLogger()->warning('Disk is warm.');

        self::assertSame([], $this->records->takeErrors());
    }

    public function testAMessageOverSeveralLinesStaysOneRecord(): void
    {
        $this->runLogger()->error("Disk on fire.\nCall the owner.");

        self::assertSame([['level' => LogLevel::ERROR, 'message' => "Disk on fire.\nCall the owner."]], $this->records->takeErrors());
    }

    public function testAnErrorTheTestAssertsThroughTheHelperIsRemovedFromTheRunsRecords(): void
    {
        $records = LogRecords::ofThisRun();
        self::assertNotNull($records);
        new RecordingLogger($records)->error('Disk on fire in this run.');

        self::assertLoggedError(LogLevel::ERROR, 'Disk on fire in this run.');

        self::assertSame([], $records->takeErrors());
    }

    private function runLogger(): RecordingLogger
    {
        return new RecordingLogger($this->records);
    }
}
