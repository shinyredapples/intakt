<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Psr\Log\LoggerInterface;
use Throwable;

// Every pass, not only one whose heads changed, because a write skipped on a busy lock or a read that failed is repaired only by a later pass over the same listing.
final readonly class StatusMirrors
{
    public function __construct(
        private FindChangedPullRequests $find,
        private StartedRounds $rounds,
        private RoundStatusMirror $mirror,
        private LoggerInterface $logger,
    ) {
    }

    // After the head step, so a round it queued shows in the same tick, and also when that step failed, whose failure stays the one the tick reports.
    /**
     * @param Closure(): void $headStep
     *
     * @throws Throwable
     */
    public function reconcileAfter(WatchedProject $project, Closure $headStep): void
    {
        try {
            $headStep();
            // Boundary: the status pass still runs, because no label should wait on the heads, and the head step's failure is rethrown unchanged.
        } catch (Throwable $failure) {
            $this->reconcileBeside($project, $failure);

            throw $failure;
        }

        $this->reconcileEveryOpenWithRound($project);
    }

    private function reconcileBeside(WatchedProject $project, Throwable $headFailure): void
    {
        try {
            $this->reconcileEveryOpenWithRound($project);
            // Boundary: only logged, because the head step's failure is the one the tick reports.
        } catch (Throwable $failure) {
            $this->logger->error('The status pass of {project} failed after its head step failed: {reason}', ['project' => $project->name, 'reason' => $failure->getMessage(), 'head_failure' => $headFailure->getMessage(), 'exception' => $failure]);
        }
    }

    // One pull request that fails stops none of the others, and the first failure is rethrown at the end so the tick still reports it.
    /**
     * @throws Throwable
     */
    private function reconcileEveryOpenWithRound(WatchedProject $project): void
    {
        $first = null;

        foreach ($this->find->openOf($project->name) as $change) {
            $failure = $this->reconcileOne($project->name, $change);
            $first ??= $failure;
        }

        if ($first instanceof Throwable) {
            throw $first;
        }
    }

    private function reconcileOne(string $project, OpenChange $change): ?Throwable
    {
        try {
            if ($this->rounds->hasRoundOf($project, $change)) {
                $this->mirror->reconcileOrThrow($project, $change->id);
            }

            return null;
            // Boundary: kept and returned, so the pass goes on with the next pull request and rethrows it once all were tried.
        } catch (Throwable $failure) {
            $this->logger->error('The status label of {change} of {project} was not reconciled: {reason}', ['change' => $change->id->value, 'project' => $project, 'reason' => $failure->getMessage(), 'exception' => $failure]);

            return $failure;
        }
    }
}
