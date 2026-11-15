<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StackedBarSvg::class)]
#[CoversClass(GroupedBarSvg::class)]
#[CoversClass(LineSvg::class)]
#[CoversClass(ChartCanvas::class)]
#[CoversClass(ChartPlot::class)]
#[CoversClass(ChartBar::class)]
#[UsesClass(YScale::class)]
#[UsesClass(ChartPalette::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
#[UsesClass(JourneyDates::class)]
final class ChartSvgTest extends TestCase
{
    public function testAStackStartsOnTheBaselineAndEndsInARoundedTopAfterASurfaceGap(): void
    {
        $svg = new StackedBarSvg()->svg($this->chart(ChartForm::StackedBars, [new ChartSeries('Build', [100]), new ChartSeries('Review', [100])]));

        self::assertStringContainsString('<rect x="183.2" y="148" width="593.6" height="132" fill="#2a78d6"/>', $svg);
        self::assertStringContainsString('<path d="M183.2,146 V20 Q183.2,16 187.2,16 H772.8 Q776.8,16 776.8,20 V146 Z" fill="#eb6834"/>', $svg);
    }

    public function testASeriesWithNothingOnADayDrawsNoMarkAndTheOneBelowGetsTheRoundedTop(): void
    {
        $svg = new StackedBarSvg()->svg($this->chart(ChartForm::StackedBars, [new ChartSeries('Build', [100]), new ChartSeries('Review', [0])]));

        self::assertStringContainsString('<path d="M183.2,280 V', $svg);
        self::assertStringNotContainsString('<rect x="183.2"', $svg);
        self::assertStringNotContainsString('fill="#eb6834"/>', str_replace('rx="3" fill="#eb6834"/>', '', $svg));
    }

    public function testGroupedBarsStandSideBySideWithASurfaceGap(): void
    {
        $svg = new GroupedBarSvg()->svg($this->chart(ChartForm::GroupedBars, [new ChartSeries('Created', [4]), new ChartSeries('Closed', [2])]));

        self::assertStringContainsString('<path d="M157.76,280 V20 Q157.76,16 161.76,16 H475 Q479,16 479,20 V280 Z" fill="#2a78d6"/>', $svg);
        self::assertStringContainsString('<path d="M481,280 V152 Q481,148 485,148 H798.24 Q802.24,148 802.24,152 V280 Z" fill="#eb6834"/>', $svg);
    }

    public function testADayWithoutAValueBreaksTheLineAndTheLastValueIsLabelled(): void
    {
        $chart = new DailyChart('open', 'Open', ChartForm::Line, $this->days(3), [new ChartSeries('Open issues', [1, null, 3])]);

        $svg = new LineSvg()->svg($chart);

        self::assertStringContainsString('<path d="M56,199M790,77" fill="none" stroke="#2a78d6"', $svg);
        self::assertStringContainsString('<circle cx="790" cy="77" r="4" fill="#2a78d6"', $svg);
        self::assertStringContainsString('>Open issues 3</text>', $svg);
    }

    public function testALineWithoutAnyValueDrawsNoPathAndNoLabel(): void
    {
        $svg = new LineSvg()->svg(new DailyChart('empty', 'Empty', ChartForm::Line, [], [new ChartSeries('Tokens per merge', [])]));

        self::assertStringNotContainsString('<path', $svg);
        self::assertStringNotContainsString('<circle', $svg);
    }

    public function testALegendNamesEachOfSeveralSeriesButNotASingleOne(): void
    {
        $several = new StackedBarSvg()->svg($this->chart(ChartForm::StackedBars, [new ChartSeries('Build', [1]), new ChartSeries('Review', [1])]));
        $single = new StackedBarSvg()->svg($this->chart(ChartForm::StackedBars, [new ChartSeries('Build', [1])]));

        self::assertSame([1, 1, 0], [substr_count($several, '>Build</text>'), substr_count($several, '>Review</text>'), substr_count($single, '>Build</text>')]);
    }

    public function testEverySecondDayIsLabelledOnAChartOfEighteenDays(): void
    {
        $svg = new GroupedBarSvg()->svg(new DailyChart('issues', 'Issues', ChartForm::GroupedBars, $this->days(18), [new ChartSeries('Created', array_fill(0, 18, 1))]));

        self::assertSame(9, substr_count($svg, 'text-anchor="middle" font-size="11"'));
        self::assertStringContainsString('>11-11</text>', $svg);
        self::assertStringNotContainsString('>11-12</text>', $svg);
    }

    public function testTextIsEscapedForXml(): void
    {
        $svg = new StackedBarSvg()->svg(new DailyChart('x', 'Tokens & <more>', ChartForm::StackedBars, [], []));

        self::assertStringContainsString('aria-label="Tokens &amp; &lt;more&gt;"', $svg);
        self::assertStringContainsString('<title>Tokens &amp; &lt;more&gt;</title>', $svg);
    }

    /**
     * @param list<ChartSeries> $series
     */
    private function chart(ChartForm $form, array $series): DailyChart
    {
        return new DailyChart('chart', 'Chart', $form, $this->days(1), $series);
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function days(int $count): array
    {
        return array_map(static fn (int $day): DateTimeImmutable => new DateTimeImmutable('2026-10-26T00:00:00Z')->modify('+'.$day.' days'), range(0, $count - 1));
    }
}
