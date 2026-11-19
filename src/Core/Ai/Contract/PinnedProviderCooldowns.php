<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface PinnedProviderCooldowns
{
    // The latest window replaces a running one, because the provider's newest answer is the best word on when it takes calls again.
    public function coolDown(PinnedProvider $provider, int $seconds): void;

    public function isCoolingDown(PinnedProvider $provider): bool;
}
