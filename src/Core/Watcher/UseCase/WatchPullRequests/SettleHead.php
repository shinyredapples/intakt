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
        private HeadBackOff $backOff,
    ) {
    }

    // Each head on its own, because one head that cannot be settled must not hold back the others or the ledger lines owed.
    /**
     * @param list<HeadDecision> $decisions
     */
    public function settleEach(WatchedProject $project, array $decisions): void
    {
        foreach ($decisions as $decision) {
            $this->backOff->tryAlone($project->name, $decision->change, fn () => $this->settle($project, $decision));
        }

        $this->starts->recordQueued($project->name);
    }

    private function settle(WatchedProject $project, HeadDecision $decision): void
    {
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
