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
        $slot = (ChartCanvas::RIGHT - ChartCanvas::LEFT) / max(1, count($chart->days));

        return $this->canvas->opened($chart->title, self::HEIGHT)
            .$this->canvas->grid($scale, ChartCanvas::RIGHT)
            .implode('', array_map(fn (int $day): string => $this->group($chart, $day, $scale, $slot), array_keys($chart->days)))
            .$this->canvas->dayLabels($chart, static fn (int $day): float => ChartCanvas::LEFT + ($day + 0.5) * $slot, self::BASELINE + 16)
            .$this->canvas->legend($chart, self::LEGEND)
            .'</svg>';
    }

    private function group(DailyChart $chart, int $day, YScale $scale, float $slot): string
    {
        $count = max(1, count($chart->series));
        $width = (self::GROUP * $slot - ChartCanvas::GAP * ($count - 1)) / $count;
        $left = ChartCanvas::LEFT + ($day + (1 - self::GROUP) / 2) * $slot;
        $bars = array_map(
            fn (int $series, ChartSeries $values): string => ($values->values[$day] ?? 0) > 0
                ? $this->canvas->roundedBar($left + $series * ($width + ChartCanvas::GAP), $width, $scale->y((int) $values->values[$day]), self::BASELINE, ChartPalette::color($series))
                : '',
            array_keys($chart->series),
            $chart->series,
        );

        return implode('', $bars);
    }
}
