<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\Domain;

use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\Domain\BenchFigures;
use Intakt\Core\Bench\Domain\RunVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BenchFigures::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(FailureCause::class)]
final class BenchFiguresTest extends TestCase
{
    public function testHarnessFailuresAreLeftOutOfTheRatesAndListed(): void
    {
        $figures = BenchFigures::from([
            new RunVerdict(false, ['d1'], [], [], [], null),
            new RunVerdict(false, [], ['d2' => FailureCause::Harness], [], [], 'provider timed out'),
        ]);

        self::assertSame(1, $figures->planted);
        self::assertSame(1.0, $figures->detectionRate());
        self::assertSame(['provider timed out'], $figures->harnessFailures);
    }

    public function testMissesAreCountedByCause(): void
    {
        $figures = BenchFigures::from([
            new RunVerdict(false, ['d1'], ['d2' => FailureCause::Reviewer, 'd3' => FailureCause::Bench], [], [], null),
        ]);

        self::assertSame(3, $figures->planted);
        self::assertSame(1, $figures->missedByReviewer);
        self::assertSame(1, $figures->missedByBench);
        self::assertEqualsWithDelta(1 / 3, $figures->detectionRate(), 0.0001);
    }

    public function testFalseAlarmsAreCountedOnCleanRunsOnly(): void
    {
        $figures = BenchFigures::from([
            new RunVerdict(true, [], [], [], [], null),
            new RunVerdict(true, [], [], [DefectMatcherTest::finding('a.js', 1), DefectMatcherTest::finding('a.js', 2)], [], null),
            new RunVerdict(false, ['d1'], [], [], [DefectMatcherTest::finding('b.js', 1)], null),
        ]);

        self::assertSame(2, $figures->cleanRuns);
        self::assertSame(1, $figures->cleanRunsWithFalseAlarm);
        self::assertSame(2, $figures->falseAlarms);
        self::assertSame(0.5, $figures->falseAlarmRate());
    }

    public function testWithoutRunsTheRatesAreUnknownNotZero(): void
    {
        $figures = BenchFigures::from([]);

        self::assertNull($figures->detectionRate());
        self::assertNull($figures->falseAlarmRate());
    }
}
