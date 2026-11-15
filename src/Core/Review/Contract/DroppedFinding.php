<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class DroppedFinding
{
    public function __construct(
        public string $id,
        public string $reason,
    ) {
    }
}
