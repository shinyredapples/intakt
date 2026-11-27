<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\AccountRanking;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\UsageWindow;
use Override;

// Cheapest tier that fits is not a step here: the accounts of one provider share one model, so the owner's order is the tie-break after the reset.
final readonly class MeasuredRoomRanking implements AccountRanking
{
    public function __construct(
        private Holds $holds,
        private CeilingStartGuard $ceilings,
    ) {
    }

    #[Override]
    public function rank(array $accounts): RankedAccounts
    {
        $leftOut = [];
        $candidates = [];

        foreach (array_values($accounts) as $position => $account) {
            $room = $this->ceilings->roomOf($account);
            $cause = $this->causeOfLeavingOut($account, $room);
            if ($cause instanceof LeftOutCause) {
                $leftOut[] = new LeftOutAccount($account, $cause, LeftOutCause::CoolingDown === $cause ? null : $room->resetsAt);

                continue;
            }

            // Subscription before paid, trusted before unknown, soonest reset, then the owner's order; no reset sorts last.
            $candidates[] = [[$account->provider->spendsAPlan() ? 0 : 1, $room->trusted ? 0 : 1, $room->resetsAt?->getTimestamp() ?? PHP_INT_MAX, $position], $account];
        }

        usort($candidates, static fn (array $one, array $other): int => $one[0] <=> $other[0]);

        return new RankedAccounts(array_map(static fn (array $candidate): Account => $candidate[1], $candidates), $leftOut);
    }

    private function causeOfLeavingOut(Account $account, AccountRoom $room): ?LeftOutCause
    {
        return match (true) {
            $this->holds->isCoolingDown($account) => LeftOutCause::CoolingDown,
            UsageWindow::Weekly === $room->atCeiling => LeftOutCause::AtWeeklyCeiling,
            UsageWindow::FiveHour === $room->atCeiling => LeftOutCause::AtShortWindowCeiling,
            default => null,
        };
    }
}
