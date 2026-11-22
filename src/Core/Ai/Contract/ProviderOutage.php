<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// The reason is one of the fixed texts below, never the provider's own error text, so the blocked reason is safe to show.
final readonly class ProviderOutage
{
    // The wait is set only for a provider that is cooling down, so a caller can tell a rate limit from an outage no wait ends (issue #2802).
    private function __construct(
        public Provider $provider,
        public string $reason,
        public ?int $secondsUntilFree = null,
    ) {
    }

    public static function disabled(Provider $provider): self
    {
        return new self($provider, 'disabled');
    }

    public static function keysRejected(Provider $provider): self
    {
        return new self($provider, 'keys rejected');
    }

    public static function coolingDown(Provider $provider, int $seconds): self
    {
        return new self($provider, sprintf('for %d s', $seconds), $seconds);
    }
}
