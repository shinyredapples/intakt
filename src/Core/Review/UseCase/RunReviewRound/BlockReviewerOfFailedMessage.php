<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

// Runs at the default priority, ahead of the listener that parks the message in the failure transport (-100), so the reviewer is blocked in the same step that parks the message.
#[AsEventListener]
final readonly class BlockReviewerOfFailedMessage
{
    public function __construct(
        private ReviewerLeases $leases,
        private ReviewRounds $rounds,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if ($event->willRetry() || !$message instanceof ReviewWithReviewer) {
            return;
        }

        // Logged instead of thrown, because the worker does not catch what a failure listener throws: it would stop before parking the message, and the message would come back again and again.
        try {
            $roundId = $this->leases->recordBlocked($message->reviewerId);
        } catch (ReviewEventNotRecorded $refused) {
            $this->logger->error('The reviewer of a failed message was not blocked; its message stays in the failure transport.', ['reviewer' => $message->reviewerId, 'exception' => $refused]);

            return;
        }

        $this->rounds->completeWhenEveryReviewerIsIn($roundId);
    }
}
