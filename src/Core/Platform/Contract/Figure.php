<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

final readonly class Figure
{
    public function __construct(
        public int|float $amount,
        public FigureKind $kind,
    ) {
    }
}
