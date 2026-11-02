<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Throwable;

final readonly class SettleHead
{
    public function __construct(
        private StaleVerdicts $stale,
        private HeadsWithoutRound $withoutRound,
        private RoundStarts $starts,
    ) {
    }

    // Each step on its own, because one head that cannot be settled must not hold back the others or the ledger lines owed; it stays due, and the tick still fails so the failure is seen.
    /**
     * @param list<HeadDecision> $decisions
     */
    public function settleEach(WatchedProject $project, array $decisions): void
    {
        $steps = array_map(fn (HeadDecision $decision): Closure => fn () => $this->settle($project, $decision), $decisions);
        $steps[] = fn () => $this->starts->recordQueued($project->name);
        $failures = [];

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        if ([] !== $failures) {
            throw HeadsNotSettled::after($failures);
        }
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
        // Ended only after the stale check, because an unchanged listing never names the head again, so a failed check must leave it due.
        $this->withoutRound->endIfRed($project->name, $decision);
    }
}
