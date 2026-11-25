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
    public const string PROPOSAL = 'Proposal:';

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
        // A field of the output contract rather than words in the text, because plain code is to route a gap in a gate that passed without parsing prose.
        public bool $gate_gap = false,
        // Ignored, so the schema never asks a reviewer for it and an answer naming it is refused; only plain code merges findings, and StoredFindingNormalizer keeps it in stored JSON.
        #[Ignore]
        public FindingIds $merged = new FindingIds(),
        // Ignored, so the schema never asks a reviewer for it, because only the judge's reason for a rejected or deferred P1 or P2 belongs here; private, so the MCP answers that json_encode findings keep their shape; StoredFindingNormalizer keeps it in stored JSON.
        #[Ignore]
        private ?string $judge_note = null,
    ) {
    }

    public function judgeNote(): ?string
    {
        return $this->judge_note;
    }

    public function withJudgeNote(string $note): self
    {
        return clone ($this, ['judge_note' => $note]);
    }

    public function withSeverity(Severity $severity): self
    {
        return clone ($this, ['severity' => $severity]);
    }

    // Only the owner decides the user guide's structure and the docs gate's limits, so the Technical writer's proposal on them is a P3 follow-up the builder never acts on (docs/design/05-architecture/07-reviews.md#the-technical-writer).
    public function proposesToOwner(): bool
    {
        return Severity::P3 === $this->severity && FixableBy::Owner === $this->fixable_by && $this->namesProposal();
    }

    public function namesProposal(): bool
    {
        return 0 === stripos(ltrim($this->requirement ?? ''), self::PROPOSAL);
    }

    public function withFixableBy(FixableBy $fixableBy): self
    {
        return clone ($this, ['fixable_by' => $fixableBy]);
    }

    public function withMerged(FindingIds $ids): self
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
