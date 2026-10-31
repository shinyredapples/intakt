<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable with no delay, because another account of the reviewer's vendor is free now; the same reviewer starts again there, so no catch-up review is owed.
final class ReviewerMovesToAnotherAccount extends RecoverableMessageHandlingException
{
    public static function now(): self
    {
        return new self('The reviewer\'s account is cooling down; it starts again on another account of its vendor.', retryDelay: 0);
    }
}
