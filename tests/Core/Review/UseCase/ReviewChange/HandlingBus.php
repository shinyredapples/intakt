<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Runs the start of a round at once, because the application's command bus handles it in the caller, and leaves the reviewers' messages to the test's workers.
final readonly class HandlingBus implements MessageBusInterface
{
    public function __construct(private RunReviewRoundHandler $handler)
    {
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof RunReviewRound);

        return new Envelope($message, [...$stamps, new HandledStamp(($this->handler)($message), RunReviewRoundHandler::class)]);
    }
}
