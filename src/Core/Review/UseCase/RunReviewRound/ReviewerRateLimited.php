<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable with the provider's reset as its retry delay, so Messenger brings the message back once the limit has lifted; without a reset the delay stays null and the retry strategy decides.
final class ReviewerRateLimited extends RecoverableMessageHandlingException
{
    public static function after(?int $resetInSeconds): self
    {
        return new self('The provider limited the reviewer\'s call.', retryDelay: null === $resetInSeconds ? null : $resetInSeconds * 1000);
    }
}
