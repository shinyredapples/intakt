<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class EscalationReviewed
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
        public EscalationOutcome $outcome,
        public string $reasons,
    ) {
    }
}
