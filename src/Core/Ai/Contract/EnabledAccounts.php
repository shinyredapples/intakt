<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface EnabledAccounts
{
    // In the owner's order, because a call tries the accounts in it; empty while the provider has no enabled account.
    /**
     * @return list<Account>
     */
    public function of(Provider $provider): array;
}
