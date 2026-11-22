<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Review\Contract\FailureInWords;
use RuntimeException;
use Throwable;

// Carries Intakt's words and the failure's class but never the failure itself, because a provider's message or its chain can echo a key, and the worker logs this exception and keeps it in a failed message.
final class EscalationCallFailed extends RuntimeException
{
    public static function of(string $key, Throwable $failure): self
    {
        return new self(sprintf('The escalation review of key %s could not be asked: %s (%s).', $key, new FailureInWords()->of($failure::class), $failure::class));
    }
}
