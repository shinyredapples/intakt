<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class StackedBarSvg
{
    private const float BASELINE = 280;
    private const float HEIGHT = 348;
    private const float LEGEND = 328;
    private const float BAR = 0.7;

    public function __construct(private ChartCanvas $canvas = new ChartCanvas())
    {
    }

    public function svg(DailyChart $chart): string
    {
        $totals = array_map(fn (int $day): int => array_sum($this->valuesOn($chart, $day)), array_keys($chart->days));
        $scale = new YScale([] === $totals ? 0 : max($totals), self::BASELINE, self::BASELINE - ChartCanvas::TOP);
        $plot = new ChartPlot($scale, (ChartCanvas::RIGHT - ChartCanvas::LEFT) / max(1, count($chart->days)));

        return $this->canvas->opened($chart->caption(), self::HEIGHT)
            .$this->canvas->grid($scale, ChartCanvas::RIGHT)
            .implode('', array_map(fn (int $day): string => $this->stack($this->valuesOn($chart, $day), $plot, $day), array_keys($chart->days)))
            .$this->canvas->dayLabels($chart, static fn (int $day): float => $plot->x($day) + $plot->step / 2, self::BASELINE + 16)
            .$this->canvas->legend($chart, self::LEGEND)
            .'</svg>';
    }

    /**
     * @return list<int>
     */
    private function valuesOn(DailyChart $chart, int $day): array
    {
        return array_map(static fn (ChartSeries $series): int => $series->values[$day] ?? 0, $chart->series);
    }

    /**
     * @param list<int> $values
     */
    private function stack(array $values, ChartPlot $plot, int $day): string
    {
        $x = $plot->x($day) + (1 - self::BAR) / 2 * $plot->step;
        $topmost = array_key_last(array_filter($values, static fn (int $value): bool => $value > 0));
        $below = 0;
        $marks = '';

        foreach ($values as $series => $value) {
            if (0 === $value) {
                continue;
            }

            $top = $plot->y($below + $value);
            $bar = new ChartBar($x, self::BAR * $plot->step, $top, 0 === $below ? self::BASELINE : max($top + 1, $plot->y($below) - ChartCanvas::GAP));
            $marks .= $series === $topmost ? $this->canvas->roundedBar($bar, ChartPalette::color($series)) : $this->canvas->squareBar($bar, ChartPalette::color($series));
            $below += $value;
        }

        return '<g>'.$marks.'</g>';
    }
}
