<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;

final readonly class HeadsWithoutRound
{
    public function __construct(
        private WatcherLedger $ledger,
        private PendingHeads $pending,
    ) {
    }

    public function record(string $project, HeadDecision $decision): void
    {
        $this->ledger->headDecided($project, $decision);

        if (HeadAdmission::Pending === $decision->admission) {
            $this->pending->keep($project, $decision);
        }
    }

    public function settled(string $project, HeadDecision $decision): void
    {
        if ($decision->goesBack()) {
            $this->pending->forget($project, $decision->change);

            return;
        }

        $this->pending->clearFailures($project, $decision->change);
    }
}
