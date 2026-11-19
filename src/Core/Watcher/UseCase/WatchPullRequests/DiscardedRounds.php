<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\QueuedRound;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class DiscardedRounds
{
    public function __construct(
        private MessageBusInterface $commands,
        private LoggerInterface $logger,
    ) {
    }

    public function discard(?QueuedRound $queued): void
    {
        if (!$queued instanceof QueuedRound) {
            return;
        }

        try {
            $this->commands->dispatch(new DiscardQueuedRound($queued));
            // Boundary: logs a failed discard, because the failure that caused it is the one the caller rethrows.
        } catch (Throwable $discardFailure) {
            $this->logger->error('The kept snapshot of a round that was not recorded could not be discarded.', ['round' => $queued->roundId, 'exception' => $discardFailure]);
        }
    }
}
