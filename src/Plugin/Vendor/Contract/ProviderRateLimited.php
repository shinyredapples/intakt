<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ProviderRateLimited extends RuntimeException
{
    public function __construct(public readonly ?int $retryAfterSeconds, ?Throwable $previous = null)
    {
        parent::__construct('The provider limited the call (rate limit or quota).', previous: $previous);
    }

    // Null when the provider sent no reset, so a caller never invents one.
    public function resetsAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return null === $this->retryAfterSeconds ? null : $now->modify(sprintf('+%d seconds', $this->retryAfterSeconds));
    }
}
