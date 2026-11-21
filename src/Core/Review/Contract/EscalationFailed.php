<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class EscalationFailed
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
