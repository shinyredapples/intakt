<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;

final readonly class SettleHead
{
    public function __construct(
        private StaleVerdicts $stale,
        private HeadsWithoutRound $withoutRound,
        private RoundStarts $starts,
        private BootGate $boot,
    ) {
    }

    public function settle(WatchedProject $project, HeadDecision $ci): void
    {
        // Asked only for a green head and before any round, so a pending or red head never pays for a boot.
        $decision = $ci->startsRound() ? $this->boot->admit($project, $ci) : $ci;

        if ($decision->startsRound()) {
            $this->stale->noteMovedHead($project, $decision->change);
            $this->starts->startFor($project, $decision);

            return;
        }

        // Recorded before the stale check, because that check reads the new head from the repository and a commit not fetched yet must not keep a pending head from waiting its interval.
        $this->withoutRound->record($project->name, $decision);
        $this->stale->noteMovedHead($project, $decision->change);
        // Settled only after the stale check, because an unchanged listing never names the head again, so a failed check must leave it queued.
        $this->withoutRound->settled($project->name, $decision);
    }
}
