<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\MarkVerdictsStale;

use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Keeps the publications a stale mark queues, so a test sees them without a worker publishing them.
final class QueuedPublications implements MessageBusInterface
{
    /** @var list<PublishVerdict> */
    public array $messages = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof PublishVerdict);
        $this->messages[] = $message;

        return new Envelope($message);
    }
}
