<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\Process;

#[CoversClass(ConsumerProcess::class)]
#[UsesClass(ProcParents::class)]
#[UsesClass(ProcessEntry::class)]
final class ConsumerProcessTest extends TestCase
{
    private const int CONSUMER = 100;

    private const int START = 5_000;

    public function testTheConsumerIsStartedWithTheConcurrencyOptionAsTheEntrypointStartsIt(): void
    {
        self::assertSame(
            ['php', 'bin/console', 'messenger:consume', 'async', '--concurrency=3', '--memory-limit=256M', '--time-limit=3600', '--quiet'],
            ConsumerProcess::command(3),
        );
    }

    public function testAStartedConsumerEndsOnSigterm(): void
    {
        $consumer = ConsumerProcess::startedIn(dirname(__DIR__, 3), 1, ['APP_ENV' => 'test']);
        $consumer->signal(SIGTERM);

        self::assertNotNull($consumer->endedWithin(30.0));
    }

    public function testAnExitWithinTheLimitReturnsTheMeasuredSeconds(): void
    {
        $clock = new MockClock('@1000');
        $consumer = new ConsumerProcess($this->runningUntil($clock, 1000.5), $clock, $this->entries([]));
        $consumer->signal(SIGTERM);

        self::assertEqualsWithDelta(0.5, $consumer->endedWithin(1.0), 0.001);
    }

    public function testAnExitObservedAfterTheDeadlineCountsAsLate(): void
    {
        $clock = new MockClock('@1000');
        $consumer = new ConsumerProcess($this->exitsBetweenTheLastPollAndTheCheck($clock, 1001.0), $clock, $this->entries([]));
        $consumer->signal(SIGTERM);

        self::assertNull($consumer->endedWithin(1.0));
    }

    public function testAConsumerStillRunningAtTheDeadlineCountsAsLate(): void
    {
        $clock = new MockClock('@1000');
        $consumer = new ConsumerProcess($this->runningUntil($clock, 2000.0), $clock, $this->entries([]));
        $consumer->signal(SIGTERM);

        self::assertNull($consumer->endedWithin(1.0));
    }

    public function testAnExitRightAtTheLimitIsInTime(): void
    {
        $clock = new MockClock('@1000');
        $consumer = new ConsumerProcess($this->runningUntil($clock, 1001.0), $clock, $this->entries([]));
        $consumer->signal(SIGTERM);

        self::assertEqualsWithDelta(1.0, $consumer->endedWithin(1.0), 0.001);
    }

    public function testAChildOfTheConsumerIsItsWorker(): void
    {
        $consumer = $this->running($this->entries([200 => new ProcessEntry(self::CONSUMER, self::START)]));

        self::assertTrue($consumer->isWorker(200, self::START));
    }

    public function testAProcessStartedThroughAShellOfTheConsumerIsItsWorker(): void
    {
        $consumer = $this->running($this->entries([200 => new ProcessEntry(150, self::START), 150 => new ProcessEntry(self::CONSUMER, self::START)]));

        self::assertTrue($consumer->isWorker(200, self::START));
    }

    public function testARecordedPidReusedByAnotherProcessIsNoWorker(): void
    {
        $consumer = $this->running($this->entries([200 => new ProcessEntry(1, self::START)]));

        self::assertFalse($consumer->isWorker(200, self::START));
    }

    public function testAPidThatIsGoneIsNoWorker(): void
    {
        $consumer = $this->running($this->entries([]));

        self::assertFalse($consumer->isWorker(200, self::START));
    }

    public function testTheConsumerItselfIsNoWorker(): void
    {
        $consumer = $this->running($this->entries([self::CONSUMER => new ProcessEntry(1, self::START)]));

        self::assertFalse($consumer->isWorker(self::CONSUMER, self::START));
    }

    public function testNoProcessIsAWorkerOfAConsumerThatHasEnded(): void
    {
        $consumer = new ConsumerProcess($this->runningUntil(new MockClock('@1000'), 0.0), new MockClock(), $this->entries([200 => new ProcessEntry(self::CONSUMER, self::START)]));

        self::assertFalse($consumer->isWorker(200, self::START));
    }

    public function testAChildOfThisProcessIsFoundAsAWorkerThroughTheSystemsProcessTable(): void
    {
        $child = new Process(['sleep', '5']);
        $child->start();

        try {
            $proc = new ProcParents();
            $consumer = new ConsumerProcess($this->runningAs((int) getmypid()), new MockClock(), $proc->entryOf(...));
            $pid = (int) $child->getPid();

            self::assertTrue($consumer->isWorker($pid, (int) $proc->startOf($pid)));
        } finally {
            $child->stop(0);
        }
    }

    public function testAReplacementWorkerOfTheSameConsumerThatGotTheRecordedPidIsNoWorker(): void
    {
        $consumer = $this->running($this->entries([200 => new ProcessEntry(self::CONSUMER, self::START + 1)]));

        self::assertFalse($consumer->isWorker(200, self::START));
    }

    public function testAPidTakenByAReplacementWorkerRightAfterItsFirstReadIsNoWorker(): void
    {
        $reads = 0;
        // Moved away from the consumer at the first read and replaced by a new worker of the consumer at every later one, because that is the order of a PID reused between two reads.
        $entryOf = static function (int $pid) use (&$reads): ?ProcessEntry {
            return 200 !== $pid ? null : (0 === $reads++ ? new ProcessEntry(1, self::START) : new ProcessEntry(self::CONSUMER, self::START + 1));
        };

        self::assertFalse($this->running($entryOf)->isWorker(200, self::START));
    }

    public function testAWorkerWhoseShellPidWasTakenByALaterChildOfTheConsumerIsNoWorker(): void
    {
        $consumer = $this->running($this->entries([200 => new ProcessEntry(150, self::START), 150 => new ProcessEntry(self::CONSUMER, self::START + 1)]));

        self::assertFalse($consumer->isWorker(200, self::START));
    }

    private function running(Closure $entryOf): ConsumerProcess
    {
        return new ConsumerProcess($this->runningUntil(new MockClock('@1000'), 2000.0), new MockClock(), $entryOf);
    }

    private function runningUntil(MockClock $clock, float $end): Process
    {
        $process = self::createStub(Process::class);
        $process->method('isRunning')->willReturnCallback(static fn (): bool => (float) $clock->now()->format('U.u') < $end);
        $process->method('getPid')->willReturn(self::CONSUMER);

        return $process;
    }

    // Seen running at the poll that finds the deadline passed and ended at the check right after it, without the clock moving, because that is the order of an exit between the last poll and the check.
    private function exitsBetweenTheLastPollAndTheCheck(MockClock $clock, float $deadline): Process
    {
        $lateCalls = 0;
        $process = self::createStub(Process::class);
        $process->method('isRunning')->willReturnCallback(static function () use ($clock, $deadline, &$lateCalls): bool {
            return (float) $clock->now()->format('U.u') <= $deadline || 0 === $lateCalls++;
        });

        return $process;
    }

    private function runningAs(int $pid): Process
    {
        $process = self::createStub(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->method('getPid')->willReturn($pid);

        return $process;
    }

    /**
     * @param array<int, ProcessEntry> $entries
     *
     * @return Closure(int): ?ProcessEntry
     */
    private function entries(array $entries): Closure
    {
        return static fn (int $pid): ?ProcessEntry => $entries[$pid] ?? null;
    }
}
