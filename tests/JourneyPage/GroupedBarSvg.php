<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class GroupedBarSvg
{
    private const float BASELINE = 280;
    private const float HEIGHT = 348;
    private const float LEGEND = 328;
    private const float GROUP = 0.76;

    public function __construct(private ChartCanvas $canvas = new ChartCanvas())
    {
    }

    public function svg(DailyChart $chart): string
    {
        $values = array_merge([], ...array_map(static fn (ChartSeries $series): array => array_map(intval(...), $series->values), $chart->series));
        $scale = new YScale([] === $values ? 0 : max($values), self::BASELINE, self::BASELINE - ChartCanvas::TOP);
        $plot = new ChartPlot($scale, (ChartCanvas::RIGHT - ChartCanvas::LEFT) / max(1, count($chart->days)));

        return $this->canvas->opened($chart->caption(), self::HEIGHT)
            .$this->canvas->grid($scale, ChartCanvas::RIGHT)
            .implode('', array_map(fn (int $day): string => $this->group($chart, $plot, $day), array_keys($chart->days)))
            .$this->canvas->dayLabels($chart, static fn (int $day): float => $plot->x($day) + $plot->step / 2, self::BASELINE + 16)
            .$this->canvas->legend($chart, self::LEGEND)
            .'</svg>';
    }

    private function group(DailyChart $chart, ChartPlot $plot, int $day): string
    {
        $count = max(1, count($chart->series));
        // On a long chart the fixed gap would leave a bar no width, so it shrinks to a share of the group.
        $gap = min(ChartCanvas::GAP, self::GROUP * $plot->step / $count / 4);
        $width = (self::GROUP * $plot->step - $gap * ($count - 1)) / $count;
        $left = $plot->x($day) + (1 - self::GROUP) / 2 * $plot->step;
        $bars = array_map(
            fn (int $series, ChartSeries $values): string => ($values->values[$day] ?? 0) > 0
                ? $this->canvas->roundedBar(new ChartBar($left + $series * ($width + $gap), $width, $plot->y($values->values[$day]), self::BASELINE), ChartPalette::color($series))
                : '',
            array_keys($chart->series),
            $chart->series,
        );

        return implode('', $bars);
    }
}
