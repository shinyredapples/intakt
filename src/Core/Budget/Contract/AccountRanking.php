<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

// Run at every job start over the accounts the job's role may use; the owner's order is the order of the list given.
interface AccountRanking
{
    /**
     * @param list<Account> $accounts
     */
    #[NoDiscard]
    public function rank(array $accounts): RankedAccounts;
}
