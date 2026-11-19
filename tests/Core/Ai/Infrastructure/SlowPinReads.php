<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Override;
use Symfony\Component\Clock\MockClock;

// Moves the mocked clock on before each read of a pin's wait, so account windows end in turn while the failover reads the pins one after another.
final readonly class SlowPinReads implements PinnedProviderCooldowns
{
    public function __construct(
        private PinnedProviderCooldowns $pins,
        private MockClock $clock,
        private int $secondsEachReadTakes,
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
        $this->clock->sleep($this->secondsEachReadTakes);

        return $this->pins->secondsUntilFree($provider);
    }
}
