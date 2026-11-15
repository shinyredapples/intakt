<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChartRegion::class)]
#[UsesClass(DailyChart::class)]
#[UsesClass(ChartSeries::class)]
#[UsesClass(ChartForm::class)]
#[UsesClass(JourneyDates::class)]
#[UsesClass(PageRegion::class)]
final class ChartRegionTest extends TestCase
{
    public function testTheRegionShowsTheDrawingAndTheSameNumbersAsATable(): void
    {
        $chart = new DailyChart('issues-per-day', 'Issues per day', ChartForm::GroupedBars, [new DateTimeImmutable('2026-10-27'), new DateTimeImmutable('2027-01-02')], [new ChartSeries('Created', [1200, 0]), new ChartSeries('Closed', [3, null])]);

        $region = new ChartRegion()->region($chart);

        self::assertSame('issues-per-day', $region->name);
        self::assertSame(<<<'MD'
            ![Issues per day](journey/issues-per-day.svg)

            <details markdown="1"><summary>Issues per day, as a table</summary>

            | Day | Created | Closed |
            |---|---|---|
            | 10-27 | 1,200 | 3 |
            | 2027-01-02 | 0 | none |

            </details>
            MD, $region->text);
    }
}
