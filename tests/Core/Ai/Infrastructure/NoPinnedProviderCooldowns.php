<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Override;

// Stands in because a test about something else than pinned providers needs every pinned provider free.
final readonly class NoPinnedProviderCooldowns implements PinnedProviderCooldowns
{
    #[Override]
    public function coolDown(PinnedProvider $provider, int $seconds): void
    {
    }

    #[Override]
    public function isCoolingDown(PinnedProvider $provider): bool
    {
        return false;
    }

    #[Override]
    public function secondsUntilFree(PinnedProvider $provider): int
    {
        return 0;
    }
}
