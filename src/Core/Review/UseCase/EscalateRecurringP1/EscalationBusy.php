<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

// Recoverable, because the escalation that holds the key keeps its verdict, and this message, brought back after the retry strategy's delay, then reuses it instead of paying for a second answer.
final class EscalationBusy extends RecoverableMessageHandlingException
{
    public static function of(string $key): self
    {
        return new self(sprintf('Another escalation review of key %s is running.', $key));
    }
}
