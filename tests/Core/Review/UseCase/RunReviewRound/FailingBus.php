<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Override;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Fails every message, so a real worker runs its failure path without a handler.
final class FailingBus implements MessageBusInterface
{
    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw new RuntimeException('the handler failed for good');
    }
}
