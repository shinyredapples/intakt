<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Keeps the escalation reviews a judging queues, with the envelopes they were queued in, so a test runs them in the order it chooses, after the judging committed, and sees their stamps.
final class QueuedEscalations implements MessageBusInterface
{
    /** @var list<EscalateRecurringP1> */
    public array $messages = [];

    /** @var list<Envelope> */
    public array $envelopes = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof EscalateRecurringP1);
        $this->messages[] = $message;
        $envelope = new Envelope($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }
}
