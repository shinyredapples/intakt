<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;

final readonly class SettleHead
{
    public function __construct(
        private StaleVerdicts $stale,
        private HeadsWithoutRound $withoutRound,
        private RoundStarts $starts,
        private BootGate $boot,
        private StartedRounds $rounds,
    ) {
    }

    public function settle(WatchedProject $project, HeadDecision $ci): void
    {
        $decision = $this->needsBoot($project, $ci) ? $this->boot->admit($project, $ci) : $ci;

        if ($decision->startsRound()) {
            $this->stale->noteMovedHead($project, $decision->change);
            $this->starts->startFor($project, $decision);

            return;
        }

        // Recorded before the stale check, because that check reads the new head from the repository and a commit not fetched yet must not keep a pending head from waiting its interval.
        $this->withoutRound->record($project->name, $decision);
        $this->stale->noteMovedHead($project, $decision->change);

        if (HeadAdmission::FailedBoot === $decision->admission) {
            $this->stale->noteFailedBoot($project, $decision->change);
        }

        // Settled only after the stale check, because an unchanged listing never names the head again, so a failed check must leave it queued.
        $this->withoutRound->settled($project->name, $decision);
    }

    // Only a green head without a standing round, so a pending or red head never pays for a boot, and a head the listing names again (a comment, a label, a retried start after its round was recorded) is not booted again, where a transient install failure would withdraw the approval of an unchanged head.
    private function needsBoot(WatchedProject $project, HeadDecision $ci): bool
    {
        return $ci->startsRound() && !$this->rounds->standsAt($project->name, $ci->change);
    }
}
