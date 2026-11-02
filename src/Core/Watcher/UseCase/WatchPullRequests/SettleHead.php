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
    ) {
    }

    public function settle(WatchedProject $project, HeadDecision $decision): void
    {
        $this->stale->noteMovedHead($project, $decision->change);

        if ($decision->startsRound()) {
            $this->starts->startFor($project, $decision);

            return;
        }

        $this->withoutRound->record($project->name, $decision);
    }
}
