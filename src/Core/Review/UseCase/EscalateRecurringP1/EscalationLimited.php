<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable in two ways, because a busy account or a rate limit clears by itself (reviewers can hold the account's calls for an hour) and is waited out past max_retries, while a call the provider keeps failing must end parked after max_retries, where its failure stamp records escalation_failed, instead of leaving the change pending for good.
final class EscalationLimited extends RecoverableMessageHandlingException
{
    public static function untilFree(?int $resetInSeconds): self
    {
        return new self(
            'The provider limited the escalation review\'s call.',
            retryDelay: null === $resetInSeconds ? null : $resetInSeconds * 1000,
            forceRetry: true,
        );
    }

    public static function bounded(): self
    {
        return new self('The provider failed the escalation review\'s call.', forceRetry: false);
    }
}
