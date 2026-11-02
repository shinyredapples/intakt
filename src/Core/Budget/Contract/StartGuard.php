<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

// Takes no estimate of the call, because measured use alone decides whether it starts.
interface StartGuard
{
    #[NoDiscard]
    public function decide(Account $account, int $callsRunning): StartDecision;
}
