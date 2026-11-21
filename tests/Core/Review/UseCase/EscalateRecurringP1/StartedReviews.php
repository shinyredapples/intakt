<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// Keeps the review starts a rejection asks for, and refuses a second start of one round number as the real round opening does, so a test sees which re-reviews were started without reading a repository.
final class StartedReviews implements MessageBusInterface
{
    /** @var list<StartReviewAtHead> */
    public array $starts = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof StartReviewAtHead);
        $number = (int) $message->start->round->number;

        if (array_any($this->starts, static fn (StartReviewAtHead $start): bool => $start->start->round->change === $message->start->round->change && $start->start->round->number === $number)) {
            throw RoundNotNext::for($number, $number + 1);
        }

        $this->starts[] = $message;

        return new Envelope($message);
    }
}
