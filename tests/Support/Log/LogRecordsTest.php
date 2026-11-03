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

    private string $file;

    private LogRecords $records;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'intakt-log-records-test-');
        $this->records = new LogRecords($this->file);
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

    public function testAnErrorAnotherProcessLogsWhileAnAssertedOneIsRemovedStaysInTheRecord(): void
    {
        $this->runLogger()->error('Disk on fire.');
        $lock = $this->lockTheRecords();
        $removal = $this->removeInAnotherProcess($lock, 'Disk on fire.');
        $this->waitUntilItWaitsForTheLock($removal);

        fwrite($lock, '{"level":"error","message":"Smoke in the hall."}'.PHP_EOL);
        fclose($lock);
        pcntl_waitpid($removal, $status);

        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame([['level' => LogLevel::ERROR, 'message' => 'Smoke in the hall.']], $this->records->takeErrors());
    }

    private function runLogger(): RecordingLogger
    {
        return new RecordingLogger($this->records);
    }

    /**
     * @return resource
     */
    private function lockTheRecords()
    {
        $lock = fopen($this->file, 'a');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));

        return $lock;
    }

    /**
     * @param resource $lock
     */
    private function removeInAnotherProcess($lock, string $message): int
    {
        $pid = pcntl_fork();
        if (0 !== $pid) {
            return $pid;
        }

        // A started process would inherit the locked handle and wait for itself, so the child closes it before it becomes the removal.
        fclose($lock);
        pcntl_exec(PHP_BINARY, [__DIR__.'/Fixtures/remove-a-record.php', $this->file, $message]);

        exit(1);
    }

    // The kernel names what a process sleeps on, which forces the order without guessing a delay: the removal has started and waits for the lock.
    private function waitUntilItWaitsForTheLock(int $removal): void
    {
        for ($attempt = 0; $attempt < 500; ++$attempt) {
            if (str_contains((string) @file_get_contents('/proc/'.$removal.'/wchan'), 'lock')) {
                return;
            }

            usleep(10_000);
        }

        self::fail('The removal never waited for the lock.');
    }
}
