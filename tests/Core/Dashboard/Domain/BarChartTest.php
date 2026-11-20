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
    public function testTheChartIsAsWideAsItsSlotsBesideTheAxis(): void
    {
        self::assertSame(36 + 4 * 20 + 8, $this->chartOf(3, null, 0, 6)->width());
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

    public function testABarIsRoundedFourPixelsAtTheTopOnly(): void
    {
        self::assertSame('M39,154 V87 Q39,83 43,83 H49 Q53,83 53,87 V154 Z', $this->chartOf(3, null, 0, 6)->bars()[0]->outline);
    }

    public function testMissingDataDrawsNoBarButKeepsItsSlot(): void
    {
        $bar = $this->chartOf(3, null, 0, 6)->bars()[1];

        self::assertNull($bar->outline);
        self::assertSame(56.0, $bar->slotX);
        self::assertSame('No data', $bar->point->reading());
    }

    public function testAZeroDrawsABarOnePixelTallSoItReadsApartFromMissingData(): void
    {
        $bar = $this->chartOf(3, null, 0, 6)->bars()[2];

        self::assertSame('M79,154 V154 Q79,153 80,153 H92 Q93,153 93,154 V154 Z', $bar->outline);
        self::assertSame('0', $bar->point->reading());
    }

    public function testTheAxisNamesTheFirstMiddleAndLastPointInsideTheChart(): void
    {
        self::assertSame(
            [
                ['x' => 36.0, 'text' => 'p0', 'anchor' => 'start'],
                ['x' => 66.0, 'text' => 'p1', 'anchor' => 'middle'],
                ['x' => 116.0, 'text' => 'p3', 'anchor' => 'end'],
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
