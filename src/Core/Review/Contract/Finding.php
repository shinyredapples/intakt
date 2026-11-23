<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Constraints\WordCount;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class Finding
{
    // One home for the example the schema and the reviewer prompt give, so it cannot drift past the title's own rules.
    public const string TITLE_EXAMPLE = 'A large cart loses its discount';

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
        // Nullable only because a finding stored before reviewers named one has none, so NotNull still refuses a reviewer's answer without it; Symfony AI's schema keeps null in the type despite NotNull: upstream/ai-platform-not-null-promoted-property
        #[NotNull]
        // NotBlank too, because WordCount lets an empty string pass.
        #[NotBlank]
        #[WordCount(min: 3, max: 6)]
        // WordCount counts a punctuation mark as a word: upstream/validator-word-count-counts-punctuation
        #[Callback([self::class, 'refuseFewerThanThreeRealWords'])]
        #[Schema(description: 'The problem in 3 to 6 plain words, for example "'.self::TITLE_EXAMPLE.'".')]
        public ?string $title = null,
        #[NotNull]
        #[Schema(description: 'What sort of problem this is.')]
        public ?FindingKind $kind = null,
        // Ignored, so the schema never asks a reviewer for it and an answer naming it is refused; only plain code merges findings, and StoredFindingNormalizer keeps it in stored JSON.
        #[Ignore]
        public FindingIds $merged = new FindingIds(),
        // Ignored, so the schema never asks a reviewer for it, because only the judge's reason for a rejected or deferred P1 or P2 belongs here; private, so the MCP answers that json_encode findings keep their shape; StoredFindingNormalizer keeps it in stored JSON.
        #[Ignore]
        private ?string $judge_note = null,
    ) {
    }

    // A word made of punctuation alone is no word, so ". . ." is no title.
    public static function refuseFewerThanThreeRealWords(?string $title, ExecutionContextInterface $context): void
    {
        if (null === $title) {
            return;
        }

        $words = preg_split('/\s+/u', $title, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $real = array_filter($words, static fn (string $word): bool => 1 === preg_match('/[\p{L}\p{N}]/u', $word));
        if (count($real) >= 3) {
            return;
        }

        $context->buildViolation('At least three words of the title must hold a letter or a digit.')->addViolation();
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
