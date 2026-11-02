<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

use Intakt\Core\Review\Contract\Finding;

final readonly class FindingMatch
{
    public function __construct(
        public LoopFinding $loop,
        public Finding $intakt,
    ) {
    }

    public function disagreesOnSeverity(): bool
    {
        return $this->loop->severity !== $this->intakt->severity;
    }
}
