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
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class Finding
{
    public const string PROPOSAL = 'Proposal:';

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
        #[NotBlank]
        // Not Symfony's WordCount, because it counts a punctuation mark as a word, so "tidy-up" would be three: upstream/validator-word-count-counts-punctuation
        #[Callback([self::class, 'refuseOutsideThreeToSixWords'])]
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

    // One meaning of a word for both limits: a part between spaces that holds a letter or a decimal digit, so "tidy-up" and "src/Cart.php" are one word each and ". . ." and "¼ ½ ¾" are none.
    public static function refuseOutsideThreeToSixWords(?string $title, ExecutionContextInterface $context): void
    {
        if (null === $title) {
            return;
        }

        $words = preg_match_all('/[^\s\p{L}\p{Nd}]*[\p{L}\p{Nd}]\S*/u', $title);

        if ($words < 3) {
            $context->buildViolation('At least three words of the title must hold a letter or a digit.')->addViolation();
        }

        if ($words > 6) {
            $context->buildViolation('At most six words of the title may hold a letter or a digit.')->addViolation();
        }
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

    // The requirement too, because it holds the proposal marker the next round reads the routing from.
    public function withRoutingOf(self $open): self
    {
        return clone ($this, ['severity' => $open->severity, 'fixable_by' => $open->fixable_by, 'requirement' => $open->requirement]);
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
