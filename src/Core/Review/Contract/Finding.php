<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping.
// Excluded because the structured output builds it, so the container has no reason to hold it.
#[Exclude]
final readonly class Finding
{
    public function __construct(
        #[NotBlank]
        public string $id,
        public Severity $severity,
        public ?string $file,
        // Not Positive, because Symfony AI describes that with a boolean exclusiveMinimum: upstream/ai-platform-positive-boolean-exclusive-minimum
        #[GreaterThanOrEqual(1)]
        public ?int $line,
        public ?string $symbol,
        #[NotBlank]
        public string $text,
        public FixableBy $fixable_by,
        public bool $repeat,
        public ?string $requirement,
        #[Valid]
        public ?Evidence $evidence,
    ) {
    }
}
