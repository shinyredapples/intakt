<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(StartGuard::class)]
final readonly class BudgetGuard implements StartGuard
{
    // A few hours, because a gap between two calls is normal and only a reading that stays away this long needs the owner.
    public const int OUTAGE_SECONDS = 3 * 3600;

    public function __construct(
        private Holds $holds,
        private CeilingStartGuard $ceilings,
        private MeasuredUse $measured,
        private OutageNotices $notices,
    ) {
    }

    #[Override]
    public function decide(Account $account, int $callsRunning): StartDecision
    {
        $this->noticeLongOutageOf($account);
        $shared = array_first($this->holds->sharedInEffect());

        return match (true) {
            $shared instanceof SharedHold => $shared->decision(),
            $this->holds->isCoolingDown($account) => StartDecision::CoolingDown,
            default => $this->ceilings->decide($account, $callsRunning),
        };
    }

    // Merging calls no model, so an approved head still lands through a pause, a back-off and any account's cooldown.
    #[Override]
    public function decideMerge(): StartDecision
    {
        $holding = array_find($this->holds->sharedInEffect(), static fn (SharedHold $hold): bool => $hold->holdsMerges());

        return $holding?->decision() ?? StartDecision::Start;
    }

    // An account that was never read has no outage, because a newly used account waits for its first reading anyway.
    private function noticeLongOutageOf(Account $account): void
    {
        $newest = $this->newestUseOf($account);
        if ($newest instanceof WindowUse && $newest->ageInSeconds >= self::OUTAGE_SECONDS) {
            $this->notices->noticeOnce($account, $newest->takenAt);
        }
    }

    private function newestUseOf(Account $account): ?WindowUse
    {
        $uses = array_filter(array_map(fn (UsageWindow $window): ?WindowUse => $this->measured->of($account, $window), UsageWindow::cases()));
        usort($uses, static fn (WindowUse $one, WindowUse $other): int => $other->ageInSeconds <=> $one->ageInSeconds);

        return array_last($uses);
    }
}
