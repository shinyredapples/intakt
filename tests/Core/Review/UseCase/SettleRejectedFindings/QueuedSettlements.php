<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\SettleRejectedFindings;

use Intakt\Core\Review\UseCase\SettleRejectedFindings\SettleRejectedFinding;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Keeps the settled findings a judging or an escalation queues, so a test reads what would be written to memory.
final class QueuedSettlements implements MessageBusInterface
{
    /** @var list<SettleRejectedFinding> */
    public array $messages = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof SettleRejectedFinding);
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
