<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

// One mapping for every call of this vendor, so a review, a judgement and an escalation fail with the same vendor errors.
final class TypedProviderFailure
{
    // Matched on the exception type because the provider's text can change and carries anything (plan T5).
    public static function of(Throwable $failure): Throwable
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => new ProviderRateLimited($failure->getRetryAfter(), $failure),
            $failure instanceof EveryProviderOut => new NoProviderLeft($failure->getMessage(), $failure),
            $failure instanceof AuthenticationException => new ProviderRejectedTheKey($failure),
            $failure instanceof EveryAccountCoolingDown => new ProviderRateLimited($failure->secondsUntilAnAccountIsFree, $failure),
            $failure instanceof RetryableExceptionInterface => new ProviderFailedTransiently($failure),
            $failure instanceof TransportExceptionInterface => new ProviderUnreachable($failure),
            default => $failure,
        };
    }
}
