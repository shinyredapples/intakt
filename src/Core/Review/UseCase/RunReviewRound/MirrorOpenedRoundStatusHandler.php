<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;

// Runs from the queue and not in RoundOpening, because the watcher opens rounds inside its own transaction: only once the outermost one committed is the round stored, and a rolled-back round never reaches this handler to leave a label behind.
#[AsCommandHandler]
final readonly class MirrorOpenedRoundStatusHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundStatusMirror $mirror,
    ) {
    }

    // The step never throws, so a label costs the round nothing.
    public function __invoke(RoundOpened $opened): void
    {
        $round = $this->rounds->stored($opened->round);

        $this->mirror->reconcile($round->project(), new ChangeId($round->change()));
    }
}
