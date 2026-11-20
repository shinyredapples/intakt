<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(ConsumerProcess::class)]
#[UsesClass(MonotonicSeconds::class)]
final class ConsumerProcessTest extends TestCase
{
    // The exit is only observed after the deadline passed, the order a poll can see it in: late, whatever the measured time.
    public function testAnExitObservedAfterTheDeadlineIsLate(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'usleep(200000);']);
        $process->start();
        $since = MonotonicSeconds::now();
        $process->wait();

        self::assertNull(new ConsumerProcess($process)->endedWithin(0.02, $since));
    }

    public function testAnExitBeforeTheDeadlineReportsTheSecondsUntilIt(): void
    {
        $process = new Process([PHP_BINARY, '-r', '']);
        $process->start();

        $seconds = new ConsumerProcess($process)->endedWithin(10.0, MonotonicSeconds::now());

        self::assertNotNull($seconds);
        self::assertLessThanOrEqual(10.0, $seconds);
    }

    public function testAProcessStillRunningAtTheDeadlineIsLateAndKilled(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'sleep(20);']);
        $process->start();

        self::assertNull(new ConsumerProcess($process)->endedWithin(0.1, MonotonicSeconds::now()));
        self::assertFalse($process->isRunning());
    }
}
