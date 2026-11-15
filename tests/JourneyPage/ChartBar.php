<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class ChartBar
{
    public function __construct(
        public float $x,
        public float $width,
        public float $top,
        public float $bottom,
    ) {
    }
}
