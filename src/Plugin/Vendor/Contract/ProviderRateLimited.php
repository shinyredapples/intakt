<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ProviderRateLimited extends RuntimeException
{
    public readonly ?int $retryAfterSeconds;

    public function __construct(?int $retryAfterSeconds, Throwable $previous)
    {
        parent::__construct('The provider limited the call (rate limit or quota).', previous: $previous);

        // Clamped so that a negative wait never puts the reset in the past.
        $this->retryAfterSeconds = null === $retryAfterSeconds ? null : max(0, $retryAfterSeconds);
    }

    // Null when the provider sent no reset, so a caller never invents one.
    public function resetsAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return null === $this->retryAfterSeconds ? null : $now->modify(sprintf('+%d seconds', $this->retryAfterSeconds));
    }
}
