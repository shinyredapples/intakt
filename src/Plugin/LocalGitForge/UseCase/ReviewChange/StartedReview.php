<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ActEntryAnswer;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Finding;
use JsonSerializable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class StartedReview implements JsonSerializable
{
    /**
     * @param list<Finding> $act
     * @param list<string>  $reasons
     */
    public function __construct(
        public string $roundId,
        public int $round,
        public string $decision,
        public array $act,
        public array $reasons,
        public string $end,
    ) {
    }

    public static function of(ChangeReview $review): self
    {
        return new self($review->roundId, $review->round, $review->decision->value, $review->actList, [...$review->reasons], $review->end->value);
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return [...get_object_vars($this), 'act' => ActEntryAnswer::listOf($this->act)];
    }
}
