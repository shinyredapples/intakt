<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordingBus implements MessageBusInterface
{
    /** @var list<ReviewWithReviewer> */
    public array $messages = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof ReviewWithReviewer);
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
