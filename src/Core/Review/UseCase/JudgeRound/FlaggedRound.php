<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A judged round with the findings its judging put on the act list, one by one, so recurrences compare what each reviewer flagged.
#[Exclude]
final readonly class FlaggedRound
{
    /**
     * @param list<Finding> $act
     */
    public function __construct(
        public ReviewRound $round,
        public array $act,
    ) {
    }

    // A round of the same head is a review again, not a new head, so only another head can show that a P1 came back.
    public function isBeforeOnAnotherHead(self $other): bool
    {
        return $this->round->number() < $other->round->number()
            && $this->round->identity()->commits->head->hash !== $other->round->identity()->commits->head->hash;
    }
}
