<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;

final readonly class RoundStatus
{
    /**
     * @param list<ReviewerStatus> $reviewers
     */
    public function __construct(
        public string $roundId,
        public string $change,
        public int $number,
        public string $state,
        public array $reviewers,
    ) {
    }

    public static function of(ReviewRound $round): self
    {
        $reviewers = array_map(static fn (RoundReviewer $reviewer): ReviewerStatus => new ReviewerStatus($reviewer->roleName(), $reviewer->tier()->value, $reviewer->state()->value, $reviewer->failure()), $round->reviewers());

        return new self($round->id()->toRfc4122(), $round->change(), $round->number(), $round->state()->value, $reviewers);
    }
}
