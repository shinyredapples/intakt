<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The geometry of docs/design/mocks/assets/charts.js, computed on the server so the page needs no script to show the chart.
final readonly class BarChart
{
    private const int SLOT = 20;

    private const int BAR = 14;

    private const int RADIUS = 4;

    private const int HEIGHT = 180;

    private const int LEFT = 36;

    private const int RIGHT = 8;

    private const int TOP = 12;

    private const int BOTTOM = 26;

    /**
     * @param non-empty-list<ChartPoint> $points
     */
    public function __construct(
        public string $title,
        public string $takeaway,
        public string $rowHeading,
        public string $valueHeading,
        public array $points,
    ) {
    }

    public function width(): int
    {
        return self::LEFT + count($this->points) * self::SLOT + self::RIGHT;
    }

    public function height(): int
    {
        return self::HEIGHT;
    }

    public function plotTop(): int
    {
        return self::TOP;
    }

    public function plotHeight(): int
    {
        return self::HEIGHT - self::TOP - self::BOTTOM;
    }

    public function plotLeft(): int
    {
        return self::LEFT;
    }

    public function plotRight(): int
    {
        return $this->width() - self::RIGHT;
    }

    /**
     * @return list<array{y: float, text: string}>
     */
    public function gridLines(): array
    {
        $max = $this->scaleMax();

        return array_map(fn (float $share): array => ['y' => $this->y($max * $share, $max), 'text' => $this->number($max * $share)], [0.0, 0.5, 1.0]);
    }

    /**
     * @return list<array{x: float, text: string, anchor: string}>
     */
    public function axisLabels(): array
    {
        $last = count($this->points) - 1;
        $labels = [];
        foreach (array_unique([0, intdiv($last, 2), $last]) as $index) {
            $labels[] = $this->axisLabel($index, $last);
        }

        return $labels;
    }

    /**
     * @return list<ChartBar>
     */
    public function bars(): array
    {
        $max = $this->scaleMax();

        return array_map(
            fn (int $index, ChartPoint $point): ChartBar => new ChartBar($point, $this->slotX($index), self::SLOT, $this->outline($index, $point, $max)),
            array_keys($this->points),
            $this->points,
        );
    }

    // All zero or no data: a scale of 1, as in the mock, never 0.
    private function scaleMax(): float
    {
        $highest = max(0, ...array_map(static fn (ChartPoint $point): int => $point->value ?? 0, $this->points));
        if (0 === $highest) {
            return 1.0;
        }
        $power = 10 ** floor(log10($highest));

        return ceil($highest / $power * 2) / 2 * $power;
    }

    /**
     * @return array{x: float, text: string, anchor: string}
     */
    private function axisLabel(int $index, int $last): array
    {
        // The first and last labels align with their slot's outer edge, so a long label stays inside the chart.
        return match ($index) {
            0 => ['x' => $this->slotX(0), 'text' => $this->points[0]->label, 'anchor' => 'start'],
            $last => ['x' => $this->slotX($last) + self::SLOT, 'text' => $this->points[$last]->label, 'anchor' => 'end'],
            default => ['x' => $this->slotX($index) + self::SLOT / 2, 'text' => $this->points[$index]->label, 'anchor' => 'middle'],
        };
    }

    private function outline(int $index, ChartPoint $point, float $max): ?string
    {
        if (null === $point->value) {
            return null;
        }
        $left = $this->slotX($index) + (self::SLOT - self::BAR) / 2;
        $right = $left + self::BAR;
        $base = $this->y(0.0, $max);
        // A zero keeps a 1 px bar, so it reads apart from missing data, which draws none.
        $top = $base - max(1.0, $point->value / $max * $this->plotHeight());
        $radius = min(self::RADIUS, $base - $top);

        return sprintf(
            'M%1$s,%3$s V%4$s Q%1$s,%5$s %6$s,%5$s H%7$s Q%2$s,%5$s %2$s,%4$s V%3$s Z',
            $this->number($left),
            $this->number($right),
            $this->number($base),
            $this->number($top + $radius),
            $this->number($top),
            $this->number($left + $radius),
            $this->number($right - $radius),
        );
    }

    private function slotX(int $index): float
    {
        return (float) (self::LEFT + $index * self::SLOT);
    }

    private function y(float $value, float $max): float
    {
        return self::TOP + $this->plotHeight() - $value / $max * $this->plotHeight();
    }

    private function number(float $value): string
    {
        return (string) round($value, 2);
    }
}
