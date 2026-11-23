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

    public function testEachSleepRunsTheWorkATestHandedTheClockBeforeItSleeps(): void
    {
        $clock = new PinnableClock(new NativeClock());
        $clock->pinAt('2026-10-28T12:00:00+00:00');
        $seen = [];
        $clock->workOnEachSleep(static function () use ($clock, &$seen): void {
            $seen[] = $clock->now()->format('H:i');
        });

        $clock->sleep(60);
        $clock->sleep(60);

        self::assertSame(['12:00', '12:01'], $seen);
    }

    public function testASleepInsideTheWorkDoesNotStartTheWorkAgain(): void
    {
        $clock = new PinnableClock(new NativeClock());
        $clock->pinAt('2026-10-28T12:00:00+00:00');
        $runs = 0;
        $clock->workOnEachSleep(static function () use ($clock, &$runs): void {
            ++$runs;
            $clock->sleep(60);
        });

        $clock->sleep(60);

        self::assertSame(1, $runs);
        self::assertSame('2026-10-28T12:02:00+00:00', $clock->now()->format('c'));
    }

    public function testASleepInsideTheWorkThroughATimeZoneViewDoesNotStartTheWorkAgain(): void
    {
        $clock = new PinnableClock(new NativeClock());
        $clock->pinAt('2026-10-28T12:00:00+00:00');
        $runs = 0;
        $clock->workOnEachSleep(static function () use ($clock, &$runs): void {
            ++$runs;
            $clock->withTimeZone('UTC')->sleep(60);
        });

        $clock->sleep(60);

        self::assertSame(1, $runs);
    }

    public function testAKernelInTheTestEnvironmentOutsideAPhpunitRunKeepsTheRealClock(): void
    {
        $run = new Process([PHP_BINARY, __DIR__.'/Fixtures/name-the-clock-outside-phpunit.php'], dirname(__DIR__, 2), [LogRecords::FILE_VARIABLE => false]);
        $run->run();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame(Clock::class, trim($run->getOutput()));
    }
}
