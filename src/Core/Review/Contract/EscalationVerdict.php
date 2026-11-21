<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class EscalationVerdict
{
    public function __construct(
        public EscalationOutcome $outcome,
        public string $reasons,
    ) {
    }
}
