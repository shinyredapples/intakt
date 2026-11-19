<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface PinnedProviderCooldowns
{
    // A window ending later than the running one extends it, one ending earlier leaves it, because answers in flight arrive in any order and the provider is not usable before every window it named has ended.
    /**
     * @throws PinnedProviderCooldownNotSaved
     */
    public function coolDown(PinnedProvider $provider, int $seconds): void;

    public function isCoolingDown(PinnedProvider $provider): bool;

    // At least a second while the window runs, so a cooling pinned provider is never reported free and retried at once; 0 once it is usable.
    public function secondsUntilFree(PinnedProvider $provider): int;
}
