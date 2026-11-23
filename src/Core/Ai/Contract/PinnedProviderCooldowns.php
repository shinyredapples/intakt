<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface PinnedProviderCooldowns
{
    // Capped at AccountCooldowns::LONGEST_SECONDS like an account's window. A window ending later than the running one extends it, one ending earlier leaves it, because answers in flight arrive in any order and the provider is not usable before every window it named has ended.
    /**
     * @throws PinnedProviderCooldownNotSaved
     */
    public function coolDown(PinnedProvider $provider, int $seconds): void;

    public function isCoolingDown(PinnedProvider $provider): bool;

    // Zero once the window has ended, else the seconds left rounded up, so a cooling pinned provider is never reported free and retried at once.
    public function secondsUntilFree(PinnedProvider $provider): int;
}
