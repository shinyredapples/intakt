<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

interface MeasuredUse
{
    #[NoDiscard]
    public function record(WindowReading $reading): ReadingOutcome;

    public function of(Account $account, UsageWindow $window): ?WindowUse;
}
