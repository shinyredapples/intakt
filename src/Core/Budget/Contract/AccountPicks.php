<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Provider;

// Every start's pick goes into the ledger, so the owner can see why an account was or was not used (design: Order of use, D6 of #4016).
interface AccountPicks
{
    public function record(Provider $provider, RankedAccounts $pick): void;

    // Null before the provider's first recorded start, and when its newest row does not read, because an older pick would show a state that no longer holds.
    public function latestOf(Provider $provider): ?AccountPick;
}
