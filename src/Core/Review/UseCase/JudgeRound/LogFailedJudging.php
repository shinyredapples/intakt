<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

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

        try {
            $this->logger->error('The judging of a round failed for good; the round stays unjudged and its message is in the failure transport.', ['round' => $message->roundId, 'exception' => $event->getThrowable()]);
            // Boundary: writes a log that cannot be written to PHP's own error log instead of rethrowing, because the worker does not catch what a failure listener throws: it would stop before parking the message, and the message would come back again and again.
        } catch (Throwable $unlogged) {
            // Only the class, because the message of either failure can quote a credential, and this line bypasses the redaction of the application log.
            error_log(sprintf('The judging of round %s failed for good, and its log could not be written (%s).', $message->roundId, $unlogged::class));
        }
    }
}
