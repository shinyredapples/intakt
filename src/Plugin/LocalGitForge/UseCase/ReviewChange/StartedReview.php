<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class StartedReview
{
    /**
     * @var list<Finding>
     */
    public array $act;

    /**
     * @param list<Finding> $act
     * @param list<string>  $reasons
     */
    public function __construct(
        public string $roundId,
        public int $round,
        public string $decision,
        array $act,
        public array $reasons,
        public string $end,
    ) {
        $this->act = array_map(static fn (Finding $finding): Finding => $finding->withJudgeNoteFenced(), $act);
    }

    public static function of(ChangeReview $review): self
    {
        return new self($review->roundId, $review->round, $review->decision->value, $review->actList, $review->reasons, $review->end->value);
    }
}
