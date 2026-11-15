<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Closure;

// The surface, grid, fonts and rounded bar ends are those of the charts first drawn by hand, so the regenerated page keeps their look.
final readonly class ChartCanvas
{
    public const float LEFT = 56;
    public const float TOP = 16;
    public const float RIGHT = 904;
    public const float GAP = 2;
    private const int WIDTH = 920;
    private const float RADIUS = 4;
    private const int MOST_DAY_LABELS = 9;
    private const string FONT = '-apple-system, Segoe UI, Helvetica, Arial, sans-serif';

    public function opened(string $title, float $height): string
    {
        $size = 'viewBox="0 0 '.self::WIDTH.' '.$this->number($height).'" width="'.self::WIDTH.'" height="'.$this->number($height).'"';

        return '<svg '.$size.' role="img" aria-label="'.$this->text($title).'" xmlns="http://www.w3.org/2000/svg" font-family="'.self::FONT.'">'
            .'<title>'.$this->text($title).'</title>'
            .'<rect width="'.self::WIDTH.'" height="'.$this->number($height).'" fill="'.ChartPalette::SURFACE.'"/>';
    }

    public function grid(YScale $scale, float $right): string
    {
        return implode('', array_map(fn (float $tick): string => $this->gridLine($scale, $tick, $right), $scale->ticks()));
    }

    /**
     * @param Closure(int): float $x
     */
    public function dayLabels(DailyChart $chart, Closure $x, float $y): string
    {
        $every = max(1, (int) ceil(count($chart->days) / self::MOST_DAY_LABELS));
        $labelled = array_filter(array_keys($chart->days), static fn (int $day): bool => 0 === $day % $every);

        return implode('', array_map(fn (int $day): string => $this->label($x($day), $y, JourneyDates::short($chart->days[$day])), $labelled));
    }

    // A single series needs no legend, because the chart's own label names it.
    public function legend(DailyChart $chart, float $y): string
    {
        if (count($chart->series) < 2) {
            return '';
        }

        $x = self::LEFT;
        $entries = '';

        foreach ($chart->series as $index => $series) {
            $entries .= '<g><rect x="'.$this->number($x).'" y="'.$this->number($y).'" width="12" height="12" rx="3" fill="'.ChartPalette::color($index).'"/>'
                .'<text x="'.$this->number($x + 18).'" y="'.$this->number($y + 10).'" font-size="12" fill="'.ChartPalette::MUTED.'">'.$this->text($series->label).'</text></g>';
            $x += 26 + 6.6 * mb_strlen($series->label);
        }

        return $entries;
    }

    public function roundedBar(ChartBar $bar, string $color): string
    {
        $radius = min(self::RADIUS, $bar->width / 2, ($bar->bottom - $bar->top) / 2);
        $n = $this->number(...);
        $right = $bar->x + $bar->width;

        return '<path d="M'.$n($bar->x).','.$n($bar->bottom).' V'.$n($bar->top + $radius).' Q'.$n($bar->x).','.$n($bar->top).' '.$n($bar->x + $radius).','.$n($bar->top)
            .' H'.$n($right - $radius).' Q'.$n($right).','.$n($bar->top).' '.$n($right).','.$n($bar->top + $radius)
            .' V'.$n($bar->bottom).' Z" fill="'.$color.'"/>';
    }

    public function squareBar(ChartBar $bar, string $color): string
    {
        $n = $this->number(...);

        return '<rect x="'.$n($bar->x).'" y="'.$n($bar->top).'" width="'.$n($bar->width).'" height="'.$n($bar->bottom - $bar->top).'" fill="'.$color.'"/>';
    }

    public function short(int $value): string
    {
        return match (true) {
            $value >= 1_000_000 => number_format($value / 1_000_000, 1, '.', '').'M',
            $value >= 1_000 => number_format($value / 1_000, 1, '.', '').'k',
            default => (string) $value,
        };
    }

    // Two decimals are finer than a pixel and keep a rerun byte for byte the same.
    public function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return '-0' === $text ? '0' : $text;
    }

    public function text(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function gridLine(YScale $scale, float $tick, float $right): string
    {
        $y = $this->number($scale->y($tick));

        return '<line x1="'.$this->number(self::LEFT).'" x2="'.$this->number($right).'" y1="'.$y.'" y2="'.$y.'" stroke="'.ChartPalette::GRID.'" stroke-width="1"/>'
            .'<text x="48" y="'.$this->number($scale->y($tick) + 4).'" text-anchor="end" font-size="12" fill="'.ChartPalette::MUTED.'">'.$scale->label($tick).'</text>';
    }

    private function label(float $x, float $y, string $text): string
    {
        return '<text x="'.$this->number($x).'" y="'.$this->number($y).'" text-anchor="middle" font-size="11" fill="'.ChartPalette::MUTED.'">'.$this->text($text).'</text>';
    }
}
