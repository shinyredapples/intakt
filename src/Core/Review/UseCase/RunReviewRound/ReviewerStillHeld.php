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
        $seconds = max(0, $heldUntil->getTimestamp() - $now->getTimestamp());

        return new self(sprintf('Another worker holds the reviewer until %s', $heldUntil->format(DATE_ATOM)), retryDelay: $seconds * 1000);
    }
}
