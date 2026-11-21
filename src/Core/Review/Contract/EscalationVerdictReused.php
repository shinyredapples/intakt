<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class EscalationVerdictReused
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
        public EscalationOutcome $outcome,
        // The round whose escalation review gave the verdict, so the reuse can be traced back to it.
        public string $verdictRound,
    ) {
    }
}
