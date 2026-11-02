<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Override;

final readonly class BudgetGuard implements StartGuard
{
    public function __construct(
        private Holds $holds,
        private CeilingStartGuard $ceilings,
        private OutageWatch $outages,
    ) {
    }

    #[Override]
    public function decide(Account $account, int $callsRunning): StartDecision
    {
        $this->outages->watch($account);
        $shared = array_first($this->holds->sharedInEffect());

        return match (true) {
            $shared instanceof SharedHold => $shared->decision(),
            $this->holds->isCoolingDown($account) => StartDecision::CoolingDown,
            default => $this->ceilings->decide($account, $callsRunning),
        };
    }

    // Merging calls no model, so an approved head still lands through any account's cooldown and the shared holds that let merges through.
    #[Override]
    public function decideMerge(): StartDecision
    {
        $holding = array_find($this->holds->sharedInEffect(), static fn (SharedHold $hold): bool => $hold->holdsMerges());

        return $holding?->decision() ?? StartDecision::Start;
    }
}
