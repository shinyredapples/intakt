<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface EnabledAccounts
{
    /**
     * @return list<Account> in the owner's order; none while the provider has no enabled account
     */
    public function of(Provider $provider): array;
}
