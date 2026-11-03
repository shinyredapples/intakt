<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

// One worker at a time reads a round's progress and publishes it, because two that read and published unordered could show "1 of 2" after "2 of 2", or an unfinished round after "Judged".
final readonly class LiveRoundProgress
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundUpdates $updates,
        private LockFactory $locks,
    ) {
    }

    public function publishLatest(Uuid $roundId): void
    {
        $lock = $this->locks->createLock('review-round-progress-'.$roundId->toRfc4122());
        $published = null;

        // Tried without waiting, because the worker holding the lock reads the progress again after it lets go, and so publishes what this one found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce($roundId, $published);
            } finally {
                $lock->release();
            }

            if ($this->progressOf($roundId)->isSameAs($published)) {
                return;
            }
        }
    }

    private function publishedOnce(Uuid $roundId, ?RoundProgress $published): RoundProgress
    {
        $progress = $this->progressOf($roundId);

        if (!$progress->isSameAs($published)) {
            $this->updates->roundChanged($roundId, $progress);
        }

        return $progress;
    }

    private function progressOf(Uuid $roundId): RoundProgress
    {
        return $this->rounds->stored($roundId->toRfc4122())->progress();
    }
}
