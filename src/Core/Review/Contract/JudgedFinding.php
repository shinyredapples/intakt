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
        #[Schema(description: 'Always act for a P1 or P2 finding; a defer or reject for one does not remove it, it is kept with your reason. Defer and reject remove a P3 finding only. A P3 finding that is a local edit in a file the change touches, needing no new design, is act; defer is for a P3 finding that needs its own design or tests or files outside the change.')]
        public Mark $mark,
        #[Schema(description: 'The id of another finding you were given with the same cause in the same file (one change reported twice), or null.')]
        public ?string $same_cause_as = null,
        #[Schema(description: 'For a reject or defer of a P1 or P2 finding, one sentence saying why; that finding stays on the act list and your reason is shown beside it. Null otherwise.')]
        public ?string $reason = null,
    ) {
    }
}
