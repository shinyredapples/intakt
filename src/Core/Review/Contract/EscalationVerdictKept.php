<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The escalation_reviewed event: written only by the round that kept the key's verdict, so each kept verdict has one review in the ledger.
final readonly class EscalationVerdictKept
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
        public EscalationOutcome $outcome,
        // Redacted, as the kept verdict holds them, because they are model text that may quote a held credential.
        public string $reasons,
    ) {
    }
}
