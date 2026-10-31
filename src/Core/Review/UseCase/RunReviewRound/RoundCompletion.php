<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundTransition;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

// Whichever reviewer finishes last completes the round; a reviewer that finishes earlier, or a message delivered twice, finds the guard closed or the round complete and does nothing.
final readonly class RoundCompletion
{
    public function __construct(
        private EntityManagerInterface $entities,
        #[Target('review_round')]
        private WorkflowInterface $round,
    ) {
    }

    public function completeWhenEveryReviewerIsIn(Uuid $roundId): void
    {
        // Cleared so the lock reads the reviewers other workers stored, not the ones this worker loaded earlier.
        $this->entities->clear();

        $this->entities->wrapInTransaction(function () use ($roundId): void {
            $round = $this->entities->find(ReviewRound::class, $roundId, LockMode::PESSIMISTIC_WRITE);

            if ($round instanceof ReviewRound && $this->round->can($round, RoundTransition::Complete->value)) {
                $this->round->apply($round, RoundTransition::Complete->value);
            }
        });
    }
}
