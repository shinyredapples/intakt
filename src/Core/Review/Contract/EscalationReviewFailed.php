<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The escalation_failed event: no verdict is kept for the key, so a later round of it is escalated again.
final readonly class EscalationReviewFailed
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
        // Intakt's own words, never the model's answer, because the answer that failed is untrusted text.
        public string $reason,
    ) {
    }
}
