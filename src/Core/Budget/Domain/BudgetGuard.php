<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Watchdog\Contract\Halts;
use Override;

final readonly class BudgetGuard implements StartGuard
{
    public function __construct(
        private Holds $holds,
        private CeilingStartGuard $ceilings,
        private OutageWatch $outages,
        private Halts $halts,
        private DiskHeadroom $disk,
    ) {
    }

    // A watchdog halt wins over every hold, because only the owner lifts it; any standing card refuses, since a start names no project yet. A full disk comes next, because no other hold frees it and a start would fail mid-step; work already running never asks again.
    #[Override]
    public function decide(Account $account, int $callsRunning): StartDecision
    {
        $this->outages->watch($account);
        $shared = array_first($this->holds->sharedInEffect());

        return match (true) {
            $this->isHalted() => StartDecision::Halted,
            $this->isDiskHeld() => StartDecision::DiskLow,
            $shared instanceof SharedHold => $shared->decision(),
            $this->holds->isCoolingDown($account) => StartDecision::CoolingDown,
            default => $this->ceilings->decide($account, $callsRunning),
        };
    }

    // Merging calls no model, so an approved head still lands through any account's cooldown and the shared holds that let merges through, but never through a halt.
    #[Override]
    public function decideMerge(): StartDecision
    {
        if ($this->isHalted()) {
            return StartDecision::Halted;
        }

        // A merge calls no model, yet it is held because it writes the mirror and the ledger, which fail mid-step on a full disk.
        if ($this->isDiskHeld()) {
            return StartDecision::DiskLow;
        }

        $holding = array_find($this->holds->sharedInEffect(), static fn (SharedHold $hold): bool => $hold->holdsMerges());

        return $holding?->decision() ?? StartDecision::Start;
    }

    private function isHalted(): bool
    {
        return [] !== $this->halts->standing();
    }

    private function isDiskHeld(): bool
    {
        return $this->disk->held() instanceof DiskHeld;
    }
}
