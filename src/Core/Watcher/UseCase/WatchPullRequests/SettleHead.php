<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;

final readonly class SettleHead
{
    public function __construct(
        private HeadGate $gate,
        private StaleVerdicts $stale,
        private HeadsWithoutRound $withoutRound,
        private RoundStarts $starts,
    ) {
    }

    public function settle(WatchedProject $project, HeadDecision $decision): void
    {
        // First, because a fresh round replaces the stale row of a head that boots again, so the withdrawal it still owes must be taken before.
        $this->stale->withdrawOwed($project, $decision->change);
        $decision = $this->bootedIfGreen($project, $decision);

        if ($decision->startsRound()) {
            $this->stale->noteMovedHead($project, $decision->change);
            $this->starts->startFor($project, $decision);

            return;
        }

        $this->starts->restoreIfStarted($project, $decision->change);
        // Recorded before the stale check, because that check reads the new head from the repository and a commit not fetched yet must not keep a pending head from waiting its interval.
        $this->withoutRound->record($project->name, $decision);
        // Withdrawn before the stale check, because that check throws when the repository cannot be read and the head's next boot may pass or crash, so a withdrawal not owed by then would never be sent.
        $this->stale->noteRefusedHead($project, $decision);
        $this->stale->noteMovedHead($project, $decision->change);
        // Settled only after the stale check, because an unchanged listing never names the head again, so a failed check must leave it queued.
        $this->withoutRound->settled($project->name, $decision);
    }

    // Booted also when the head already has a round, because a head that stopped booting must lose the approvals that round gave it.
    private function bootedIfGreen(WatchedProject $project, HeadDecision $decision): HeadDecision
    {
        return $decision->startsRound() ? $this->gate->admit($project, $decision) : $decision;
    }
}
