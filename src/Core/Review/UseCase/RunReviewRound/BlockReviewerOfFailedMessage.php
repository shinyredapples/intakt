<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Throwable;

// After the retry listener, because willRetry() is only decided there; before the message is parked, so the reviewer is blocked in the same step that parks it.
#[AsEventListener(before: SendFailedMessageToFailureTransportListener::class, after: SendFailedMessageForRetryListener::class)]
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

        try {
            $roundId = $this->leases->recordBlocked($message->reviewerId);
            // Boundary: logs every failure instead of throwing because the worker does not catch what a failure listener throws, so it would stop before parking the message.
        } catch (Throwable $failure) {
            $this->logger->error('The reviewer of a failed message was not blocked; its message stays in the failure transport.', ['reviewer' => $message->reviewerId, 'exception' => $failure]);

            return;
        }

        $this->rounds->completeWhenEveryReviewerIsIn($roundId);
    }
}
