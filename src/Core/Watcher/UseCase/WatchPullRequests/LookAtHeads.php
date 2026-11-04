<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

final readonly class LookAtHeads
{
    public function __construct(
        private HeadLocks $locks,
        private HeadDecisions $decisions,
        private SettleHeads $settle,
    ) {
    }

    public function lookAtDue(WatchedProject $project): void
    {
        $this->locks->whileHoldingDue($project->name, function (array $heads) use ($project): void {
            $this->settle->settleEach($project, $this->decisions->of($project->name, $heads));
        });
    }
}
