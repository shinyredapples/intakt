<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\BarChart;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartPoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BarChart::class)]
#[CoversClass(ChartPoint::class)]
#[UsesClass(ChartBar::class)]
final class BarChartTest extends TestCase
{
    public function testAShortSeriesSpreadsOverTheSmallestPlotWidth(): void
    {
        $chart = $this->chartOf(3, null, 0, 6);

        self::assertSame(36 + 280 + 8, $chart->width());
        self::assertSame(70.0, $chart->bars()[1]->slotWidth);
    }

    public function testALongSeriesGivesEachPointASlot20PxWideWithABar14PxWide(): void
    {
        $chart = $this->chartOf(...array_fill(0, 20, 5));

        self::assertSame(36 + 20 * 20 + 8, $chart->width());
        self::assertSame('M39,154 V16 Q39,12 43,12 H49 Q53,12 53,16 V154 Z', $chart->bars()[0]->outline);
    }

    public function testTheGridHasThreeLinesFromZeroToARoundedScale(): void
    {
        self::assertSame(
            [['y' => 154.0, 'text' => '0'], ['y' => 83.0, 'text' => '3'], ['y' => 12.0, 'text' => '6']],
            $this->chartOf(3, null, 0, 6)->gridLines(),
        );
    }

    public function testTheScaleRoundsUpToTheNextHalfStepOfItsPowerOfTen(): void
    {
        self::assertSame(['0', '7.5', '15'], array_column($this->chartOf(13, 2)->gridLines(), 'text'));
    }

    public function testAChartWithoutDataKeepsAScaleOfOne(): void
    {
        self::assertSame(['0', '0.5', '1'], array_column($this->chartOf(null, null)->gridLines(), 'text'));
    }

    public function testABarIsRoundedFourPixelsAtTheTopOnlyAndCappedAt28PxWide(): void
    {
        self::assertSame('M57,154 V87 Q57,83 61,83 H81 Q85,83 85,87 V154 Z',$this->chartOf(3, null, 0, 6)->bars()[0]->outline);
    }

    public function testMissingDataDrawsNoBarButKeepsItsSlot(): void
    {
        $bar = $this->chartOf(3, null, 0, 6)->bars()[1];

        self::assertNull($bar->outline);
        self::assertSame(106.0, $bar->slotX);
        self::assertSame('No data', $bar->point->reading());
    }

    public function testAZeroDrawsABarOnePixelTallSoItReadsApartFromMissingData(): void
    {
        $bar = $this->chartOf(3, null, 0, 6)->bars()[2];

        self::assertSame('M197,154 V154 Q197,153 198,153 H224 Q225,153 225,154 V154 Z', $bar->outline);
        self::assertSame('0', $bar->point->reading());
    }

    public function testTheAxisNamesTheFirstMiddleAndLastPointInsideTheChart(): void
    {
        self::assertSame(
            [
                ['x' => 36.0, 'text' => 'p0', 'anchor' => 'start'],
                ['x' => 141.0, 'text' => 'p1', 'anchor' => 'middle'],
                ['x' => 316.0, 'text' => 'p3', 'anchor' => 'end'],
            ],
            $this->chartOf(3, null, 0, 6)->axisLabels(),
        );
    }

    public function testASinglePointIsNamedOnce(): void
    {
        self::assertSame([['x' => 36.0, 'text' => 'p0', 'anchor' => 'start']], $this->chartOf(4)->axisLabels());
    }

    /**
     * @param int<0, max>|null ...$values
     */
    private function chartOf(?int ...$values): BarChart
    {
        $points = [];
        foreach (array_values($values) as $index => $value) {
            $points[] = new ChartPoint('p'.$index, $value);
        }

        return new BarChart('Rounds per day', 'A takeaway.', 'Day', 'Rounds', $points);
    }
}
