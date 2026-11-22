<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Override;

// Cools one pinned provider right after another one was read, as another worker's 429 would while the accounts are scanned.
final readonly class PinCoolingAfterARead implements PinnedProviderCooldowns
{
    public function __construct(
        private PinnedProviderCooldowns $store,
        private PinnedProvider $coolsAfter,
        private PinnedProvider $cooled,
        private int $seconds,
    ) {
    }

    #[Override]
    public function coolDown(PinnedProvider $provider, int $seconds): void
    {
        $this->store->coolDown($provider, $seconds);
    }

    #[Override]
    public function isCoolingDown(PinnedProvider $provider): bool
    {
        return $this->store->isCoolingDown($provider);
    }

    #[Override]
    public function secondsUntilFree(PinnedProvider $provider): int
    {
        $seconds = $this->store->secondsUntilFree($provider);
        if ($provider->key() === $this->coolsAfter->key()) {
            $this->store->coolDown($this->cooled, $this->seconds);
        }

        return $seconds;
    }
}
