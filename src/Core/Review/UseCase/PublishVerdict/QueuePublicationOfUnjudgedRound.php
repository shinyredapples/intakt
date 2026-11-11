<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

// A judging that failed for good never reaches the judge transition, so without this the loop would wait for a status that never comes; the round is published as not reviewed.
#[AsEventListener]
final readonly class QueuePublicationOfUnjudgedRound
{
    public function __construct(
        private MessageBusInterface $commands,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if ($event->willRetry() || !$message instanceof JudgeRound) {
            return;
        }

        try {
            $this->commands->dispatch(new PublishVerdict($message->roundId));
            // Boundary: logs every failure instead of throwing because the worker does not catch what a failure listener throws, so it would stop before parking the message.
        } catch (Throwable $failure) {
            $this->logger->error('The publication of a round whose judging failed for good was not queued; its head gets no status.', ['round' => $message->roundId, 'exception' => $failure]);
        }
    }
}
