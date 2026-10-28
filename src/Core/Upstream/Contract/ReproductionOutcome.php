<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\Contract;

final readonly class ReproductionOutcome
{
    public function __construct(
        public string $slug,
        public ReproductionStatus $status,
        public string $output,
    ) {
    }

    public function stillFails(): bool
    {
        return ReproductionStatus::StillFails === $this->status;
    }
}
