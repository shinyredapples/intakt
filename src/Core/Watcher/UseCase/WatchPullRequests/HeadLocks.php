<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

// One lock per pull request, held from the stale check to the round start, because a tick that looked at a newer head while an older one was still starting would miss that round.
final readonly class HeadLocks
{
    public function __construct(
        private PendingHeads $pending,
        private LockFactory $locks,
        private InstallWideFailures $installWide,
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
        } catch (Throwable $failure) {
            $this->giveBackAfter($failure, $held);

            throw $failure;
        }

        $this->giveBack($held);
    }

    private function lockOf(string $project, OpenChange $open): LockInterface
    {
        return $this->locks->createLock('watcher-change-'.hash('sha256', json_encode([$project, $open->id->value], JSON_THROW_ON_ERROR)));
    }

    // Reported outside any head's back-off, because every head's lock lives in the one store, so its failure is no head's own.
    /**
     * @param list<LockInterface> $held
     */
    private function giveBack(array $held): void
    {
        $failure = array_first($this->releaseEach($held));

        if ($failure instanceof LockReleasingException) {
            $this->installWide->lockStoreFailed($failure);

            throw $failure;
        }
    }

    // The failure that ended the tick is the one rethrown, so a release failure after it is only reported, which logs it with its trace.
    /**
     * @param list<LockInterface> $held
     */
    private function giveBackAfter(Throwable $failure, array $held): void
    {
        $releaseFailures = $this->releaseEach($held);
        $storeFailure = $failure instanceof LockAcquiringException ? $failure : array_first($releaseFailures);

        if ($storeFailure instanceof Throwable) {
            $this->installWide->lockStoreFailed($storeFailure);
        }
    }

    // Every lock is given back, also after one failed, because a lock left held keeps its head from every tick until its time to live runs out.
    /**
     * @param list<LockInterface> $held
     *
     * @return list<LockReleasingException>
     */
    private function releaseEach(array $held): array
    {
        $failures = [];

        foreach ($held as $lock) {
            try {
                $lock->release();
            } catch (LockReleasingException $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }
}
