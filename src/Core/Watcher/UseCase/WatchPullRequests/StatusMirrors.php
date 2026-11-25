<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;

// Every pass and every open pull request with a round, not only a due head, because a write the lock skipped or a read that failed changes nothing the listing would show.
final readonly class StatusMirrors
{
    public function __construct(
        private FindChangedPullRequests $open,
        private LatestRoundStatus $rounds,
        private RoundStatusMirror $mirror,
    ) {
    }

    public function reconcileEveryOpenWithRound(string $project): void
    {
        foreach ($this->open->openOf($project) as $change) {
            // Review's rounds and not the watcher's own, because a round queued by another door has no started row here.
            if ($this->rounds->of($project, $change->id->value) instanceof ReviewStatus) {
                $this->mirror->reconcile($project, $change->id);
            }
        }
    }
}
