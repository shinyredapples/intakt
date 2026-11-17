<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\NotBlank;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class JudgedFinding
{
    public function __construct(
        #[NotBlank]
        public string $id,
        #[Schema(description: 'Always act for a P1 or P2 finding; defer and reject are for a P3 finding only.')]
        public Mark $mark,
        #[Schema(description: 'The id of another finding you were given with the same cause in the same file (one change reported twice), or null.')]
        public ?string $same_cause_as = null,
    ) {
    }
}
