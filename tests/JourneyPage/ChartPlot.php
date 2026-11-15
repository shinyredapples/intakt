<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class ChartPlot
{
    public function __construct(public YScale $scale, public float $step)
    {
    }

    public function x(int $day): float
    {
        return ChartCanvas::LEFT + $day * $this->step;
    }

    public function y(float $value): float
    {
        return $this->scale->y($value);
    }
}
