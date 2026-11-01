<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

// Only logged, because the design gives a judging that failed for good no place on the round or in its ledger; the error is the notice that the round stays unjudged.
#[AsEventListener]
final readonly class LogFailedJudging
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if ($event->willRetry() || !$message instanceof JudgeRound) {
            return;
        }

        $this->logger->error('The judging of a round failed for good; the round stays unjudged and its message is in the failure transport.', ['round' => $message->roundId, 'exception' => $event->getThrowable()]);
    }
}
