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
        // Required, because a rejection goes back to the reviewer that holds the finding and a confirmation to the next build, and either must say why.
        // Not blank and holding a visible character, because trim() leaves form feeds and Unicode spaces, which say nothing either.
        #[NotBlank]
        #[Regex(pattern: '/[^\s\p{Z}\p{C}]/u', message: 'The reasons must hold more than whitespace.')]
        public string $reasons,
    ) {
    }
}
