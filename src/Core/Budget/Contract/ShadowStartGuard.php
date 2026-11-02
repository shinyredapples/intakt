<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

// The same decision as the start guard, against the lower ceilings the owner sets for shadow work, so shadow work leaves the capacity the existing loop needs.
interface ShadowStartGuard
{
    #[NoDiscard]
    public function decide(Account $account, int $callsRunning): StartDecision;
}
