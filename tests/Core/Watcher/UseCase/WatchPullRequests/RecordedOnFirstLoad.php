<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;

// Records the round's owed line right after the first row is read, as another tick of the same project would, so a start that read the row once and then reads it again meets a line no longer owed.
final class RecordedOnFirstLoad
{
    private bool $recorded = false;

    public function __construct(
        private readonly StartedRounds $rounds,
        private readonly StartedRound $round,
    ) {
    }

    public function postLoad(): void
    {
        if ($this->recorded) {
            return;
        }

        $this->recorded = true;
        $this->rounds->recordOnce($this->round, static function (): void {});
    }
}
