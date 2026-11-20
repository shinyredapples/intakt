<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ChartBar
{
    // The outline is null for missing data: the slot keeps its tooltip and table row, but draws no bar.
    public function __construct(
        public ChartPoint $point,
        public float $slotX,
        public float $slotWidth,
        public ?string $outline,
    ) {
    }
}
