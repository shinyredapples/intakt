<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Tests\Support\Log\LogRecords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Process\Process;

#[CoversClass(PinnableClock::class)]
#[CoversClass(PinnableClockFactory::class)]
#[UsesClass(LogRecords::class)]
final class PinnableClockTest extends KernelTestCase
{
    public function testTheContainersClockRunsOnRealTimeUntilATestPinsIt(): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        $before = $clock->now();

        usleep(20000);

        self::assertGreaterThan($before, $clock->now());
    }

    public function testAPinnedClockSleepsWithoutWaiting(): void
    {
        $clock = new PinnableClock(new NativeClock());
        $clock->pinAt('2026-10-28T12:00:00+00:00');
        $start = hrtime(true);

        $clock->sleep(3600);

        self::assertLessThan(1e9, hrtime(true) - $start);
        self::assertSame('2026-10-28T13:00:00+00:00', $clock->now()->format('c'));
    }

    public function testAKernelInTheTestEnvironmentOutsideAPhpunitRunKeepsTheRealClock(): void
    {
        $run = new Process([PHP_BINARY, __DIR__.'/Fixtures/name-the-clock-outside-phpunit.php'], dirname(__DIR__, 2), [LogRecords::FILE_VARIABLE => false]);
        $run->run();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame(Clock::class, trim($run->getOutput()));
    }
}
