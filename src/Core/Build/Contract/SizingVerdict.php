<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class SizingVerdict
{
    /**
     * @param list<ReadinessGap> $missing
     */
    public function __construct(
        #[Schema(description: 'True only when the task states an observable result, an acceptance condition and a single outcome.')]
        public bool $ready,
        #[Schema(description: 'What the task lacks: empty when ready is true, at least one item when it is false.')]
        public array $missing,
        #[Schema(description: 'The size of the change against the size contract: small, medium, large, or split when it must be split before it is built.')]
        public SizeTier $size,
        #[PositiveOrZero]
        #[Schema(description: 'Your estimate of the changed lines.')]
        public int $estimated_lines,
        #[Length(min: 1, max: 1000)]
        #[Schema(description: 'Your reason in a few sentences.')]
        public string $reason,
    ) {
    }

    // Checked in plain code, because the schema cannot tie the flag to the list: a task that is not ready with nothing missing would be parked with an empty comment.
    public function agreesWithItself(): bool
    {
        return $this->ready === ([] === $this->missing) && count($this->missing) === count(array_unique(array_column($this->missing, 'value')));
    }
}
