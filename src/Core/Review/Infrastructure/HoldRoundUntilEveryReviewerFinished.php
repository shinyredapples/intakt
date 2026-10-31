<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

// A guard, not a check in the handler, so no door can complete a round that still waits for a reviewer.
#[AsEventListener(event: 'workflow.review_round.guard.complete')]
final readonly class HoldRoundUntilEveryReviewerFinished
{
    public function __invoke(GuardEvent $event): void
    {
        $round = $event->getSubject();

        if ($round instanceof ReviewRound && !$round->everyReviewerFinished()) {
            $event->setBlocked(true, 'a reviewer has no verdict or failure yet');
        }
    }
}
