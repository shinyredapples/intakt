<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Psr\Log\LoggerInterface;
use Throwable;

// One head's failure backs off that head alone, because a head that fails on every try once held back every other head of its lane for 40 minutes.
final readonly class HeadBackOff
{
    public function __construct(
        private FailedHeads $failed,
        private PendingHeads $pending,
        private ManagerRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): T $step
     *
     * @return T|null
     */
    public function tryAlone(string $project, OpenChange $head, Closure $step): mixed
    {
        try {
            return $step();
        } catch (ForgeRateLimited $paused) {
            $this->waitForTheReset($project, $head, $paused);

            return null;
            // Boundary: backs off only this head on any failure of its step, because it must not end the tick for the heads after it.
        } catch (Throwable $failure) {
            $this->failed->isHeldByTheDisk($failure) ? $this->waitForRoom($project, $head, $failure) : $this->backOffAlone($project, $head, $failure);

            return null;
        }
    }

    // No failure, because the forge refused the read before any request and named when it answers again; counted as one, a pause of a few ticks handed a healthy head over.
    private function waitForTheReset(string $project, OpenChange $head, ForgeRateLimited $paused): void
    {
        $this->logger->info('A head waits for the forge\'s rate-limit reset.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash, 'resets_at' => $paused->resetsAt->format(DATE_ATOM)]);

        try {
            $this->pending->waitUntil($project, $head, $paused->resetsAt);
            // Boundary: backs off this head when its wait cannot be stored, because that is a failure of this head alone and must not end the tick for the heads after it.
        } catch (Throwable $failure) {
            $this->backOffAfterAFailedWait($project, $head, $failure);
        }
    }

    // Not logged as a failure, because a disk hold is a wait; contained like the rate-limit wait, because a row that rejects the wait must not end the tick for the heads after it.
    private function waitForRoom(string $project, OpenChange $head, Throwable $hold): void
    {
        try {
            $this->failed->backOff($project, $head, $hold);
            // Boundary: logs a wait that cannot be stored and reopens the entity manager, so the head stays due and the next heads still run.
        } catch (Throwable $notStored) {
            $this->logger->error('A head\'s wait for room on the disk could not be stored; it stays due for the next tick.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash, 'exception' => $notStored]);
            $this->reopenEntityManager();
        }
    }

    // Contained, because a database error of this head's row can reject the back-off as it rejected the wait, and the heads after it must still run; nothing was stored, so the head stays due for the next tick.
    private function backOffAfterAFailedWait(string $project, OpenChange $head, Throwable $failure): void
    {
        try {
            $this->backOffAlone($project, $head, $failure);
            // Boundary: logs a back-off that cannot be stored and reopens the entity manager, so the head stays due and the next heads still run.
        } catch (Throwable $notBackedOff) {
            $this->logger->error('A head\'s back-off could not be stored; it stays due for the next tick.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash, 'exception' => $notBackedOff]);
            $this->reopenEntityManager();
        }
    }

    private function backOffAlone(string $project, OpenChange $head, Throwable $failure): void
    {
        $this->logger->error('A head failed; it backs off alone.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash, 'exception' => $failure]);
        $this->reopenEntityManager();
        $this->failed->backOff($project, $head, $failure);
    }

    // A failed flush closes the entity manager for every holder and Doctrine refuses to reopen it, so without a reset the back-off and every later head would fail too.
    private function reopenEntityManager(): void
    {
        $entities = $this->doctrine->getManager();

        if ($entities instanceof EntityManagerInterface && !$entities->isOpen()) {
            $this->doctrine->resetManager();
        }
    }
}
