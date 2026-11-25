<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;

// Runs from the queue after the round is committed, because the reconcile reads the stored round and a caller's enclosing transaction may still roll it back; for every door that queues a round, not only the watcher.
#[AsCommandHandler]
final readonly class MirrorQueuedRoundHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundStatusMirror $mirror,
    ) {
    }

    public function __invoke(RoundOpened $opened): void
    {
        $round = $this->rounds->stored($opened->round);

        $this->mirror->reconcile($round->project(), new ChangeId($round->change()));
    }
}
