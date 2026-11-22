<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EscalationRequest
{
    public function __construct(
        public Finding $finding,
        // The round's whole diff, because the escalation review has no tools and judges only the code it is shown.
        public string $diff,
    ) {
    }
}
