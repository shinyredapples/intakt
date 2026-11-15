<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// The plot stops short of the right edge, so the label at the line's last point fits beside it.
final readonly class LineSvg
{
    private const float BASELINE = 260;
    private const float HEIGHT = 300;
    private const float RIGHT = 790;

    public function __construct(private ChartCanvas $canvas = new ChartCanvas())
    {
    }

    public function svg(DailyChart $chart): string
    {
        $values = array_filter(array_merge([], ...array_map(static fn (ChartSeries $series): array => $series->values, $chart->series)), is_int(...));
        $scale = new YScale([] === $values ? 0 : max($values), self::BASELINE, self::BASELINE - ChartCanvas::TOP);
        $plot = new ChartPlot($scale, count($chart->days) > 1 ? (self::RIGHT - ChartCanvas::LEFT) / (count($chart->days) - 1) : 0);

        return $this->canvas->opened($chart->title, self::HEIGHT)
            .$this->canvas->grid($scale, self::RIGHT)
            .$this->canvas->dayLabels($chart, $plot->x(...), self::BASELINE + 16)
            .implode('', array_map(fn (int $series, ChartSeries $values): string => $this->line($values, ChartPalette::color($series), $plot), array_keys($chart->series), $chart->series))
            .'</svg>';
    }

    // A day without a value breaks the line, so a gap is never drawn as zero; a value with no neighbor to join becomes a dot.
    private function line(ChartSeries $series, string $color, ChartPlot $plot): string
    {
        $drawn = array_filter($series->values, is_int(...));

        if ([] === $drawn) {
            return '';
        }

        $path = '';
        $dots = '';

        foreach ($drawn as $day => $value) {
            $joinedBefore = isset($drawn[$day - 1]);
            $x = $this->canvas->number($plot->x($day));
            $y = $this->canvas->number($plot->y($value));

            if ($joinedBefore || isset($drawn[$day + 1])) {
                $path .= ($joinedBefore ? 'L' : 'M').$x.','.$y;
            } elseif (array_key_last($drawn) !== $day) {
                $dots .= '<circle cx="'.$x.'" cy="'.$y.'" r="3" fill="'.$color.'"/>';
            }
        }

        return ('' === $path ? '' : '<path d="'.$path.'" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linejoin="round"/>').$dots.$this->end($series, $color, $plot);
    }

    private function end(ChartSeries $series, string $color, ChartPlot $plot): string
    {
        $day = (int) array_key_last(array_filter($series->values, is_int(...)));
        $value = (int) $series->values[$day];
        $x = $plot->x($day);
        $y = $plot->y($value);

        return '<circle cx="'.$this->canvas->number($x).'" cy="'.$this->canvas->number($y).'" r="4" fill="'.$color.'" stroke="'.ChartPalette::SURFACE.'" stroke-width="2"/>'
            .'<text x="'.$this->canvas->number($x + 8).'" y="'.$this->canvas->number($y + 4).'" font-size="12" fill="'.ChartPalette::INK.'">'.$this->canvas->text($series->label.' '.$this->canvas->short($value)).'</text>';
    }
}
