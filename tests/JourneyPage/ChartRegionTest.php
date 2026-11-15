<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\FigureKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChartRegion::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
#[UsesClass(ChartForm::class)]
#[UsesClass(FigureSource::class)]
#[UsesClass(JourneyDates::class)]
#[UsesClass(PageRegion::class)]
#[UsesClass(TokenCharts::class)]
#[UsesClass(IssueCharts::class)]
#[UsesClass(Issue::class)]
#[UsesClass(Usage::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UsageVendor::class)]
#[UsesClass(UsageAccount::class)]
#[UsesClass(UsageLane::class)]
#[UsesClass(DayRange::class)]
final class ChartRegionTest extends TestCase
{
    public function testTheRegionShowsTheDrawingAndTheSameNumbersAsATable(): void
    {
        $chart = new DailyChart('issues-per-day', 'Issues per day', ChartForm::GroupedBars, [new DateTimeImmutable('2026-10-27'), new DateTimeImmutable('2027-01-02')], [new ChartSeries('Created', [1200, 0]), new ChartSeries('Closed', [3, null])], new FigureSource(FigureKind::Counted, 'from the issues the GitHub API lists'));

        $region = new ChartRegion()->region($chart);

        self::assertSame('issues-per-day', $region->name);
        self::assertSame(<<<'MD'
            ![Issues per day (counted)](journey/issues-per-day.svg)

            Counted: from the issues the GitHub API lists.

            <details markdown="1"><summary>Issues per day (counted), as a table</summary>

            | Day | Created (counted) | Closed (counted) |
            |---|---|---|
            | 10-27 | 1,200 | 3 |
            | 2027-01-02 | 0 | none |

            </details>
            MD, $region->text);
    }

    public function testATokenChartNamesItsSeriesAsMeasuredAndSaysTheyComeFromTheUsageFile(): void
    {
        $chart = new TokenCharts()->charts(new Usage([new TokenUsage(new DateTimeImmutable('2026-10-27'), UsageVendor::Claude, UsageAccount::Claude1, UsageLane::Build, 5)]), [])[0];

        $region = new ChartRegion()->region($chart);

        self::assertStringContainsString('![Tokens per day by lane (measured)]', $region->text);
        self::assertStringContainsString("Measured: the providers' usage answers, summed per day in `docs/journey/usage.jsonl`.", $region->text);
        self::assertStringContainsString('| Day | Claude, building (measured) |', $region->text);
    }

    public function testAnIssueChartNamesItsSeriesAsCountedAndSaysTheyComeFromTheForgesApi(): void
    {
        $chart = new IssueCharts()->charts([new Issue(null, new DateTimeImmutable('2026-10-27'), null, false)])[1];

        $region = new ChartRegion()->region($chart);

        self::assertStringContainsString('Counted: from the issues the GitHub API lists.', $region->text);
        self::assertStringContainsString('| Day | Open issues (counted) |', $region->text);
    }
}
