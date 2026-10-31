<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface AccountCooldowns
{
    public function coolDown(Account $account, ?int $resetInSeconds): void;

    public function isCoolingDown(Account $account): bool;

    // Zero while an account of the provider is free, so a reviewer moves to it at once.
    public function secondsUntilAnAccountIsFree(Provider $provider): int;
}
