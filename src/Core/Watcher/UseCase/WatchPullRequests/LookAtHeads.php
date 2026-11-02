<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;

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
            $this->settle->settleEach($project, ($this->admit)($project->name, $heads));
        });
    }
}
