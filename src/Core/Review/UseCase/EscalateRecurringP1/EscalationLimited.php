<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable with the provider's reset as its delay, but bounded by the transport's max_retries (forceRetry off), because an escalation the provider keeps refusing must end parked, where its failure stamp records escalation_failed, instead of leaving the change pending for good.
final class EscalationLimited extends RecoverableMessageHandlingException
{
    public static function after(?int $resetInSeconds): self
    {
        return new self(
            'The provider limited or failed the escalation review\'s call.',
            retryDelay: null === $resetInSeconds ? null : $resetInSeconds * 1000,
            forceRetry: false,
        );
    }
}
