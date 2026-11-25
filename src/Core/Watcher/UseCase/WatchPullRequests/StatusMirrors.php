<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;

final readonly class StatusMirrors
{
    public function __construct(
        private FindChangedPullRequests $find,
        private StartedRounds $rounds,
        private RoundStatusMirror $status,
    ) {
    }

    // Every open pull request with a round at any head, changed or not, because a write skipped for a busy lock or a read that failed is repaired only by a later pass over a listing that may name nothing new; the mirror reads the stored round itself, so a repeat is cheap and harmless.
    public function reconcileOpenOf(string $project): void
    {
        foreach ($this->find->openOf($project) as $change) {
            if ($this->rounds->hasRoundOf($project, $change)) {
                $this->status->reconcile($project, $change->id);
            }
        }
    }
}
