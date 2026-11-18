<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenCharts::class)]
#[CoversClass(DayRange::class)]
#[UsesClass(Usage::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UsageAccount::class)]
#[UsesClass(UsageLane::class)]
#[UsesClass(UsageVendor::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
#[UsesClass(FigureSource::class)]
final class TokenChartsTest extends TestCase
{
    public function testTheLaneChartHasOneSeriesPerVendorAndLaneThatHasUsageInTheirFixedOrder(): void
    {
        $lanes = $this->charts([
            $this->row(UsageAccount::GptSubscription, UsageLane::Review, 3),
            $this->row(UsageAccount::Claude2, UsageLane::Build, 5),
            $this->on('2026-10-28', 2),
        ])[0];

        self::assertEquals([new ChartSeries('Claude, building', [5, 2]), new ChartSeries('GPT, reviewing', [3, 0])], $lanes->series);
    }

    public function testTheAccountChartNamesEachAccountByVendorAndNumber(): void
    {
        $accounts = $this->charts(array_map(fn (UsageAccount $account): TokenUsage => $this->row($account, UsageLane::Build, 1), array_reverse(UsageAccount::cases())))[1];

        self::assertSame(['Claude account 1', 'Claude account 2', 'Claude API key', 'GPT subscription'], array_map(static fn (ChartSeries $series): string => $series->label, $accounts->series));
    }

    public function testEveryDayFromTheFirstToTheLastUsageDayIsADayOfTheChart(): void
    {
        $lanes = $this->charts([$this->on('2026-10-30', 1), $this->on('2026-10-28', 4)])[0];

        self::assertSame(['10-28', '10-29', '10-30'], array_map(static fn (DateTimeImmutable $day): string => $day->format('m-d'), $lanes->days));
        self::assertSame([4, 0, 1], $lanes->series[0]->values);
    }

    public function testTokensPerMergeDividesADaysTokensByItsMergesAndLeavesADayWithoutAMergeEmpty(): void
    {
        $perMerge = $this->charts([$this->on('2026-10-27', 7), $this->on('2026-10-28', 4)], ['2026-10-27' => 2])[2];

        self::assertSame([3, null], $perMerge->series[0]->values);
    }

    public function testReservedTokensAreDrawnInNoTokenChart(): void
    {
        $charts = $this->charts([$this->on('2026-10-27', 6, reserved: 4)], ['2026-10-27' => 2]);

        self::assertSame([[6], [6], [3]], array_map(static fn (DailyChart $chart): array => $chart->series[0]->values, $charts));
    }

    public function testAnEmptyUsageFileGivesChartsWithoutDays(): void
    {
        $charts = $this->charts([]);

        self::assertSame([[], [], []], array_map(static fn (DailyChart $chart): array => $chart->days, $charts));
        self::assertSame([], $charts[0]->series);
    }

    private function row(UsageAccount $account, UsageLane $lane, int $tokens): TokenUsage
    {
        $vendor = UsageAccount::GptSubscription === $account ? UsageVendor::Gpt : UsageVendor::Claude;

        return new TokenUsage(new DateTimeImmutable('2026-10-27T00:00:00Z'), $vendor, $account, $lane, $tokens, 0);
    }

    private function on(string $day, int $tokens, int $reserved = 0): TokenUsage
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);

        return new TokenUsage($date, UsageVendor::Claude, UsageAccount::Claude1, UsageLane::Build, $tokens, $reserved);
    }

    /**
     * @param list<TokenUsage>   $rows
     * @param array<string, int> $mergesPerDay
     *
     * @return list<DailyChart>
     */
    private function charts(array $rows, array $mergesPerDay = []): array
    {
        return new TokenCharts()->charts(new Usage($rows), $mergesPerDay);
    }
}
