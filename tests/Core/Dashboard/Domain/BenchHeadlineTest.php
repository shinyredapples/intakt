<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

// The bench plants 5 defects and runs each change 3 times, so "15 of 15" counts each defect once per run (UX2 Trends, issue 3329).
#[CoversClass(BenchHeadline::class)]
final class BenchHeadlineTest extends TestCase
{
    public function testEveryDefectFoundInEveryRunReadsAsAllOfThemInEachRun(): void
    {
        self::assertSame('All 5 planted defects found in each of 3 runs', $this->headline(15, 15, 3)->sentence());
    }

    public function testAPartialResultNamesTheFindsOverTheRunsNotAPerRunClaim(): void
    {
        self::assertSame('12 of 15 finds of 5 planted defects over 3 runs', $this->headline(12, 15, 3)->sentence());
    }

    public function testWithoutARunCountTheFigureStaysAsPublished(): void
    {
        self::assertSame('15 of 15 planted defects found', $this->headline(15, 15, null)->sentence());
    }

    public function testAPlantedCountTheRunsDoNotDivideGivesNoPerRunClaim(): void
    {
        self::assertSame([null, '14 of 14 planted defects found'], [$this->headline(14, 14, 3)->plantedPerRun(), $this->headline(14, 14, 3)->sentence()]);
    }

    public function testASingleRunNamesNoRunsAndOneDefectIsSingular(): void
    {
        self::assertSame(['All 5 planted defects found', '1 of 2 planted defects found', 'All 1 planted defect found'], [$this->headline(5, 5, 1)->sentence(), $this->headline(1, 2, 1)->sentence(), $this->headline(1, 1, 1)->sentence()]);
    }

    public function testFalseAlarmsInWords(): void
    {
        self::assertSame(
            ['No false alarms', '1 false alarm in all', '3 false alarms in all'],
            array_map(fn (int $alarms): string => new BenchHeadline(5, 5, $alarms, '2026-11-21', 1)->falseAlarmsInWords(), [0, 1, 3]),
        );
    }

    private function headline(int $found, int $planted, ?int $runs): BenchHeadline
    {
        return new BenchHeadline($found, $planted, 0, '2026-11-21', $runs);
    }
}
