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

    // Boundary: catches every failure of one head's step, because any of them must back off only that head and let the tick go on to the next.
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
        } catch (Throwable $failure) {
            $this->backOffAlone($project, $head, $failure);

            return null;
        }
    }

    // No failure, because the forge refused the read before any request and named when it answers again; counted as one, a pause of a few ticks handed a healthy head over.
    private function waitForTheReset(string $project, OpenChange $head, ForgeRateLimited $paused): void
    {
        $this->logger->info('A head waits for the forge\'s rate-limit reset.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash, 'resets_at' => $paused->resetsAt->format(DATE_ATOM)]);

        // Boundary too, because a wait that cannot be stored is a failure of this head alone and must not end the tick for the heads after it.
        try {
            $this->pending->waitUntil($project, $head, $paused->resetsAt);
        } catch (Throwable $failure) {
            $this->backOffAlone($project, $head, $failure);
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
