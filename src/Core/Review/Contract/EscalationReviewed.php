<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class EscalationReviewed
{
    public function __construct(
        // Its reasons are redacted, because they are model text that may quote a held credential and they are kept and shown.
        public EscalationVerdict $verdict,
    ) {
    }
}
