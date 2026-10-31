<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

// Runs at the default priority, ahead of the listener that parks the message in the failure transport (-100), so the reviewer is blocked in the same step that parks the message.
#[AsEventListener]
final readonly class BlockReviewerOfFailedMessage
{
    public function __construct(private ReviewerLeases $leases, private ReviewRounds $rounds)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if ($event->willRetry() || !$message instanceof ReviewWithReviewer) {
            return;
        }

        $roundId = $this->leases->recordBlocked($message->reviewerId);
        $this->rounds->completeWhenEveryReviewerIsIn($roundId);
    }
}
