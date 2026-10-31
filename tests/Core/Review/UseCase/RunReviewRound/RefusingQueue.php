<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Override;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Passes the first message on and refuses the next, so a round has stored rows and one sent message when the dispatch breaks.
final class RefusingQueue implements MessageBusInterface
{
    private int $accepted = 0;

    public function __construct(private readonly MessageBusInterface $queue)
    {
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (1 <= $this->accepted) {
            throw new RuntimeException('queue unavailable');
        }

        ++$this->accepted;

        return $this->queue->dispatch($message, $stamps);
    }
}
