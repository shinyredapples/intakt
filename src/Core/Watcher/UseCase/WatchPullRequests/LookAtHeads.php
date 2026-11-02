<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Throwable;

final readonly class LookAtHeads
{
    public function __construct(
        private HeadLocks $locks,
        private AdmitHeads $admit,
        private SettleHead $settle,
    ) {
    }

    public function lookAtDue(WatchedProject $project): void
    {
        $this->locks->whileHoldingDue($project->name, function (array $heads) use ($project): void {
            $this->settleEach($project, ($this->admit)($project->name, $heads));
        });
    }

    // Each head on its own, because one head that cannot be settled must not hold back the others; it stays due, and the tick still fails so the failure is seen.
    /**
     * @param list<HeadDecision> $decisions
     */
    private function settleEach(WatchedProject $project, array $decisions): void
    {
        $failures = [];

        foreach ($decisions as $decision) {
            try {
                $this->settle->settle($project, $decision);
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        if ([] !== $failures) {
            throw HeadsNotSettled::after($failures);
        }
    }
}
