<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The id of a finding a reviewer calls resolved in its verdict.
final readonly class ResolvedFinding
{
    public function __construct(
        public string $id,
    ) {
    }
}
