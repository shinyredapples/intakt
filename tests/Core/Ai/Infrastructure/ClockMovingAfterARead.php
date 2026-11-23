<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Override;
use Symfony\Component\Clock\MockClock;

// Moves the clock on right after one pinned provider was read, so that pin ends before the next one is read.
final readonly class ClockMovingAfterARead implements PinnedProviderCooldowns
{
    public function __construct(
        private PinnedProviderCooldowns $store,
        private MockClock $clock,
        private PinnedProvider $movesAfter,
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
        if ($provider->key() === $this->movesAfter->key()) {
            $this->clock->sleep($this->seconds);
        }

        return $seconds;
    }
}
