<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// One lock per pull request, held from the stale check to the round start, because a tick that looked at a newer head while an older one was still starting would miss that round.
final readonly class HeadLocks
{
    public function __construct(
        private PendingHeads $pending,
        private LockFactory $locks,
    ) {
    }

    /**
     * @param Closure(list<OpenChange>): void $work
     */
    public function whileHoldingDue(string $project, Closure $work): void
    {
        $held = [];
        $heads = [];

        try {
            foreach ($this->pending->dueFor($project) as $open) {
                $lock = $this->lockOf($project, $open);

                // Tried without waiting, because a tick has a time budget and the head stays due for the next tick.
                if (!$lock->acquire(false)) {
                    continue;
                }

                $held[] = $lock;
                // Read again under the lock, because another tick may have settled this head or queued a newer one between the read and the acquire.
                $current = $this->pending->stillDue($project, $open);

                if ($current instanceof OpenChange) {
                    $heads[] = $current;
                }
            }

            $work($heads);
        } finally {
            array_walk($held, static fn (LockInterface $lock) => $lock->release());
        }
    }

    private function lockOf(string $project, OpenChange $open): LockInterface
    {
        return $this->locks->createLock('watcher-change-'.hash('sha256', json_encode([$project, $open->id->value], JSON_THROW_ON_ERROR)));
    }
}
