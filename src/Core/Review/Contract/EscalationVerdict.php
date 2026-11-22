<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\NotBlank;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class EscalationVerdict
{
    public function __construct(
        public EscalationOutcome $outcome,
        // Required, because a rejection goes back to the reviewer that holds the finding and a confirmation to the next build, and either must say why.
        #[NotBlank(normalizer: 'trim')]
        public string $reasons,
    ) {
    }
}
