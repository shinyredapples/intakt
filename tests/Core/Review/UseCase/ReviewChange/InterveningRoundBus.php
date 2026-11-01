<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Closure;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Opens another round of the change between the caller's request and its handler, so the race the round number guards against happens in a fixed order.
final readonly class InterveningRoundBus implements MessageBusInterface
{
    public function __construct(
        private Closure $openAnotherRound,
        private MessageBusInterface $bus,
    ) {
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        ($this->openAnotherRound)();

        return $this->bus->dispatch($message, $stamps);
    }
}
