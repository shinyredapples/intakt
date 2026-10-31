<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use DateTimeImmutable;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable with the rest of the hold as its retry delay, so Messenger brings the message back once the hold has ended and not before.
final class ReviewerStillHeld extends RecoverableMessageHandlingException
{
    public static function until(DateTimeImmutable $heldUntil, DateTimeImmutable $now): self
    {
        $microseconds = ($heldUntil->getTimestamp() - $now->getTimestamp()) * 1_000_000
            + (int) $heldUntil->format('u') - (int) $now->format('u');
        // Rounded up: a delay of 0 would bring the message back at once, to be refused again.
        $seconds = max(0, intdiv($microseconds + 999_999, 1_000_000));

        return new self(sprintf('Another worker holds the reviewer until %s', $heldUntil->format(DATE_ATOM)), retryDelay: $seconds * 1000);
    }
}
