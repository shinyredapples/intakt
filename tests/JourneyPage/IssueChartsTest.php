<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueCharts::class)]
#[UsesClass(DayRange::class)]
#[UsesClass(Issue::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
final class IssueChartsTest extends TestCase
{
    public function testIssuesAreCountedOnTheDayTheyWereCreatedAndTheDayTheyWereClosed(): void
    {
        $perDay = new IssueCharts()->charts($this->issues())[0];

        self::assertEquals([new ChartSeries('Created', [2, 1, 0]), new ChartSeries('Closed', [0, 1, 1])], $perDay->series);
    }

    public function testTheOpenIssuesAreCountedAtTheEndOfEachDay(): void
    {
        $open = new IssueCharts()->charts($this->issues())[1];

        self::assertEquals([new ChartSeries('Open issues', [2, 2, 1])], $open->series);
    }

    public function testTheDaysRunFromTheFirstIssueToTheLastCreationOrClosing(): void
    {
        $perDay = new IssueCharts()->charts($this->issues())[0];

        self::assertSame(['10-27', '10-28', '10-29'], array_map(static fn (DateTimeImmutable $day): string => $day->format('m-d'), $perDay->days));
    }

    public function testNoIssuesGiveChartsWithoutDays(): void
    {
        $charts = new IssueCharts()->charts([]);

        self::assertSame([[], []], array_map(static fn (DailyChart $chart): array => $chart->days, $charts));
    }

    /**
     * @return list<Issue>
     */
    private function issues(): array
    {
        return [
            new Issue(null, new DateTimeImmutable('2026-10-27T00:00:00Z'), new DateTimeImmutable('2026-10-28T10:00:00Z'), false),
            new Issue(null, new DateTimeImmutable('2026-10-27T23:59:59Z'), new DateTimeImmutable('2026-10-29T10:00:00Z'), true),
            new Issue(null, new DateTimeImmutable('2026-10-28T09:00:00Z'), null, false),
        ];
    }
}
