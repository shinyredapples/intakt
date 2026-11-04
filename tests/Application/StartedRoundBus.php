<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Override;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Answers every command as a handler that started a round, so the dashboard flashes its success line without a real review.
final readonly class StartedRoundBus implements MessageBusInterface
{
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope(new stdClass(), [new HandledStamp('round-2', 'handler')]);
    }
}
