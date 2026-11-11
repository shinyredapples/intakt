<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// The announcements apart from the reviewer messages, so a test delivers the reviewers by their index.
final class RecordingBus implements MessageBusInterface
{
    /** @var list<ReviewWithReviewer> */
    public array $messages = [];

    /** @var list<RoundOpened> */
    public array $announced = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof RoundOpened) {
            $this->announced[] = $message;

            return new Envelope($message, $stamps);
        }

        assert($message instanceof ReviewWithReviewer);
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
