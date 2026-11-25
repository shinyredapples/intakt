<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Psr\Log\LoggerInterface;
use Throwable;

// Every pass and every open pull request with a round, not only a due head, because a write the lock skipped or a read that failed changes nothing the listing would show.
final readonly class StatusMirrors
{
    public function __construct(
        private FindChangedPullRequests $open,
        private LatestRoundStatus $rounds,
        private RoundStatusMirror $mirror,
        private LoggerInterface $logger,
    ) {
    }

    // The failure that ended the tick is the one its caller rethrows, so a failure of the status pass after it is only logged, with both.
    public function reconcileAfter(Throwable $tickFailure, string $project): void
    {
        try {
            $this->reconcileEveryOpenWithRound($project);
            // Boundary: logged and dropped, because the next tick runs the status pass again and the tick's own failure must reach its caller.
        } catch (Throwable $notReconciled) {
            $this->logger->error('The status pass of {project} failed after its tick had failed.', ['project' => $project, 'exception' => $notReconciled, 'tick_failure' => $tickFailure]);
        }
    }

    // The first failure is rethrown only after the loop, so one pull request whose round or labels cannot be read never keeps the others' labels from being repaired on this pass.
    public function reconcileEveryOpenWithRound(string $project): void
    {
        $firstFailure = null;
        foreach ($this->open->openOf($project) as $change) {
            $failure = $this->reconcileOne($project, $change->id);
            $firstFailure ??= $failure;
        }

        if ($firstFailure instanceof Throwable) {
            throw $firstFailure;
        }
    }

    private function reconcileOne(string $project, ChangeId $change): ?Throwable
    {
        try {
            // Review's rounds and not the watcher's own, because a round queued by another door has no started row here.
            if ($this->rounds->of($project, $change->value) instanceof ReviewStatus) {
                $this->mirror->reconcile($project, $change);
            }

            return null;
            // Boundary: logged and returned, because the pass goes on with the other open pull requests and rethrows the first failure at its end.
        } catch (Throwable $notReconciled) {
            $this->logger->error('The status of {change} in {project} could not be reconciled.', ['project' => $project, 'change' => $change->value, 'exception' => $notReconciled]);

            return $notReconciled;
        }
    }
}
