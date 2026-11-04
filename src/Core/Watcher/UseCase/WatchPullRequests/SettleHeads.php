<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;

final readonly class SettleHeads
{
    public function __construct(
        private SettleHead $settle,
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
            $this->backOff->tryAlone($project->name, $decision->change, fn () => $this->settle->settle($project, $decision));
        }

        $this->starts->recordQueued($project->name);
    }
}
