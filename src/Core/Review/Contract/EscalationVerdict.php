<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class EscalationVerdict
{
    public function __construct(
        public EscalationOutcome $outcome,
        // Required with a visible character, because a rejection goes back to the reviewer and a confirmation to the next build, both must say why; marks and the fillers that render blank are no reason.
        #[NotBlank]
        #[Regex(pattern: '/(?![\x{115F}\x{1160}\x{2800}\x{3164}\x{FFA0}])[\p{L}\p{N}\p{P}\p{S}]/u', message: 'The reasons must hold a visible character.')]
        public string $reasons,
    ) {
    }
}
