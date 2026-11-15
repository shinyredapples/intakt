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
        $step = count($chart->days) > 1 ? (self::RIGHT - ChartCanvas::LEFT) / (count($chart->days) - 1) : 0;
        $x = static fn (int $day): float => ChartCanvas::LEFT + $day * $step;

        return $this->canvas->opened($chart->title, self::HEIGHT)
            .$this->canvas->grid($scale, self::RIGHT)
            .$this->canvas->dayLabels($chart, $x, self::BASELINE + 16)
            .implode('', array_map(fn (int $series, ChartSeries $values): string => $this->line($values, ChartPalette::color($series), $scale, $step), array_keys($chart->series), $chart->series))
            .'</svg>';
    }

    // A day without a value breaks the line, so a gap is never drawn as zero.
    private function line(ChartSeries $series, string $color, YScale $scale, float $step): string
    {
        $path = '';
        $previous = null;

        foreach ($series->values as $day => $value) {
            if (null !== $value) {
                $path .= (null === $previous ? 'M' : 'L').$this->point(ChartCanvas::LEFT + $day * $step, $scale->y($value));
            }

            $previous = $value;
        }

        return '' === $path ? '' : '<path d="'.$path.'" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linejoin="round"/>'.$this->end($series, $color, $scale, $step);
    }

    private function end(ChartSeries $series, string $color, YScale $scale, float $step): string
    {
        $day = (int) array_key_last(array_filter($series->values, is_int(...)));
        $value = (int) $series->values[$day];
        $x = ChartCanvas::LEFT + $day * $step;
        $y = $scale->y($value);

        return '<circle cx="'.$this->canvas->number($x).'" cy="'.$this->canvas->number($y).'" r="4" fill="'.$color.'" stroke="'.ChartPalette::SURFACE.'" stroke-width="2"/>'
            .'<text x="'.$this->canvas->number($x + 8).'" y="'.$this->canvas->number($y + 4).'" font-size="12" fill="'.ChartPalette::INK.'">'.$this->canvas->text($series->label.' '.$this->canvas->short($value)).'</text>';
    }

    private function point(float $x, float $y): string
    {
        return $this->canvas->number($x).','.$this->canvas->number($y);
    }
}
