<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

// Run at every job start over the accounts the job's role may use, and the list is in the owner's order because that order is the last tie-break. The caller hands in the accounts of a tier it already resolved (one provider's accounts, which share one model), because the ranking itself knows no tiers.
interface AccountRanking
{
    /**
     * @param list<Account> $accounts
     */
    #[NoDiscard]
    public function rank(array $accounts): RankedAccounts;
}
