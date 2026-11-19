<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Override;

// Runs the given step just before one pin's wait is read, so a test forces something (an answer in flight arriving) to happen after the failover handled the accounts before that pin.
final readonly class InterruptedPinRead implements PinnedProviderCooldowns
{
    /**
     * @param Closure(): void $interruption
     */
    public function __construct(
        private PinnedProviderCooldowns $pins,
        private PinnedProvider $interrupted,
        private Closure $interruption,
    ) {
    }

    #[Override]
    public function coolDown(PinnedProvider $provider, int $seconds): void
    {
        $this->pins->coolDown($provider, $seconds);
    }

    #[Override]
    public function isCoolingDown(PinnedProvider $provider): bool
    {
        return $this->pins->isCoolingDown($provider);
    }

    #[Override]
    public function secondsUntilFree(PinnedProvider $provider): int
    {
        if ($provider->key() === $this->interrupted->key()) {
            ($this->interruption)();
        }

        return $this->pins->secondsUntilFree($provider);
    }
}
