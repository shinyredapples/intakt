<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Domain\ReviewRound;

final readonly class RoundStatus
{
    /**
     * @param list<ReviewerStatus> $reviewers
     */
    public function __construct(
        public RoundIdentity $round,
        public string $state,
        public array $reviewers,
    ) {
    }

    public static function of(ReviewRound $round): self
    {
        $identity = new RoundIdentity($round->id()->toRfc4122(), $round->change(), $round->number());
        $reviewers = array_map(ReviewerStatus::of(...), $round->reviewers());

        return new self($identity, $round->state()->value, $reviewers);
    }
}
