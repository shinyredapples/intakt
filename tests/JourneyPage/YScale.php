<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// Four even steps up to a round top keep the gridlines on readable numbers.
final readonly class YScale
{
    private const array ROUND_TOPS = [1, 2, 4, 5, 10];
    private const int SMALLEST_TOP = 4;
    private const int STEPS = 4;

    public float $top;

    public function __construct(int $largest, private float $baseline, private float $height)
    {
        $this->top = $this->roundTop(max($largest, self::SMALLEST_TOP));
    }

    public function y(float $value): float
    {
        return $this->baseline - $value / $this->top * $this->height;
    }

    /**
     * @return list<float>
     */
    public function ticks(): array
    {
        return array_map(fn (int $step): float => $this->top * $step / self::STEPS, range(0, self::STEPS));
    }

    public function label(float $tick): string
    {
        if (0.0 === $tick) {
            return '0';
        }

        [$unit, $suffix] = match (true) {
            $this->top >= 1_000_000 => [1_000_000, 'M'],
            $this->top >= 1_000 => [1_000, 'k'],
            default => [1, ''],
        };
        $step = $this->top / self::STEPS / $unit;
        $decimals = array_find([0, 1],static fn (int $places): bool => round($step, $places) === $step) ?? 2;

        return number_format($tick / $unit, $decimals, '.', '').$suffix;
    }

    private function roundTop(int $largest): float
    {
        $power = 10 ** floor(log10($largest));

        return (float) array_find(self::ROUND_TOPS, static fn (int $round): bool => $round * $power >= $largest) * $power;
    }
}
