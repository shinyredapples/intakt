<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable in two ways, because a busy account or a rate limit clears by itself (reviewers can hold the account's calls for an hour) and is waited out past max_retries, while a call the provider keeps failing transiently must end parked, where its failure stamp records escalation_failed, instead of leaving the change pending for good.
final class EscalationLimited extends RecoverableMessageHandlingException
{
    // Forced and never parked: the rate limit's reset is the delay, and a busy account names none, so the transport's max_delay caps the wait.
    public static function untilFree(?int $resetInSeconds): self
    {
        return new self(
            'The provider limited the escalation review\'s call.',
            retryDelay: null === $resetInSeconds ? null : $resetInSeconds * 1000,
            forceRetry: true,
        );
    }

    // The one bounded error: forceRetry off, so BoundTransientEscalationFailures counts it apart from the forced retries and parks the message once its own retries run out.
    public static function bounded(): self
    {
        return new self('The provider failed the escalation review\'s call.', forceRetry: false);
    }

    // A transient failure still within its own retries, forced so the forced retries before it do not use them up.
    public static function retriedIn(int $milliseconds): self
    {
        return new self(
            'The provider failed the escalation review\'s call; it is tried again.',
            retryDelay: $milliseconds,
            forceRetry: true,
        );
    }
}
