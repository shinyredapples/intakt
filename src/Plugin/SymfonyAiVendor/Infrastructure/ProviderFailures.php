<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Throwable;

final readonly class ProviderFailures
{
    // Matched on the exception type because the provider's text can change and carries anything (plan T5).
    public function typed(Throwable $failure): Throwable
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => new ProviderRateLimited($failure->getRetryAfter(), $failure),
            $failure instanceof RetryableExceptionInterface => new ProviderFailedTransiently($failure),
            default => $failure,
        };
    }
}
