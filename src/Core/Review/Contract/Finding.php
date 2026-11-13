<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class Finding
{
    /**
     * @param list<string> $merged
     */
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
        public bool $regression = false,
        // Ignored, so the schema never asks a reviewer for it and an answer naming it is refused; only plain code merges findings, and StoredFindingNormalizer keeps it in stored JSON.
        #[Ignore]
        public array $merged = [],
    ) {
    }

    public function withSeverity(Severity $severity): self
    {
        return clone ($this, ['severity' => $severity]);
    }

    /**
     * @param list<string> $ids
     */
    public function withMerged(array $ids): self
    {
        return clone ($this, ['merged' => $ids]);
    }

    public function withId(string $id): self
    {
        return clone ($this, ['id' => $id]);
    }

    // One home for how a prompt lists a finding, so the reviewer's open findings and the builder's act list read alike.
    public function listEntry(): string
    {
        return sprintf(
            "%s [%s] %s:%s%s\nrequirement: %s\n%s",
            $this->id,
            $this->severity->value,
            $this->file ?? '-',
            $this->line ?? '-',
            null === $this->symbol ? '' : ' '.$this->symbol,
            $this->requirement ?? '-',
            $this->text,
        );
    }
}
