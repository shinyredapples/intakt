<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ResolvedFinding
{
    public function __construct(
        public string $id,
    ) {
    }
}
