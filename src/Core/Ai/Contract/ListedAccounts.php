<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// Every account the owner configured, with or without its credentials, so a view can show the ones EnabledAccounts leaves out.
interface ListedAccounts
{
    // In the owner's order; empty for a provider that has no enabled account and keeps only its implicit default.
    /**
     * @return list<Account>
     */
    public function of(Provider $provider): array;
}
