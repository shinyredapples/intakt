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
        $holder = $this->holdTheRecordsInAnotherProcess('{"level":"error","message":"Smoke in the hall."}');
        $removal = $this->startProcess([__DIR__.'/Fixtures/remove-a-record.php', $this->file, 'Disk on fire.']);
        $this->waitUntilItWaitsForTheLock($removal['process']);

        fwrite($holder['pipes'][0], 'append'.PHP_EOL);

        self::assertSame(0, proc_close($holder['process']));
        self::assertSame(0, proc_close($removal['process']));
        self::assertSame([['level' => LogLevel::ERROR, 'message' => 'Smoke in the hall.']], $this->records->takeErrors());
    }

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'intakt-log-records-test-');
        $this->records = new LogRecords($this->file);
    }

    protected function tearDown(): void
    {
        $this->records->discard();
    }

    private function runLogger(): RecordingLogger
    {
        return new RecordingLogger($this->records);
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function holdTheRecordsInAnotherProcess(string $line): array
    {
        $holder = $this->startProcess([__DIR__.'/Fixtures/hold-the-records.php', $this->file, $line]);
        self::assertSame('locked'.PHP_EOL, fgets($holder['pipes'][1]));

        return $holder;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function startProcess(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($process);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param resource $removal
     */
    // Asks the kernel's list of waiting locks, because that forces the order without guessing a delay.
    private function waitUntilItWaitsForTheLock($removal): void
    {
        $waiting = '/^\d+: -> FLOCK\s+ADVISORY\s+WRITE\s+'.proc_get_status($removal)['pid'].'\s/m';
        for ($attempt = 0; $attempt < 500; ++$attempt) {
            if (1 === preg_match($waiting, (string) file_get_contents('/proc/locks'))) {
                return;
            }

            usleep(10_000);
        }

        self::fail('The removal never waited for the lock.');
    }
}
