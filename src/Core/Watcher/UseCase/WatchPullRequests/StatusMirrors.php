<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class StatusMirrors
{
    public function __construct(
        private FindChangedPullRequests $find,
        private LatestRoundStatus $rounds,
        private RoundStatusMirror $status,
        private LoggerInterface $logger,
    ) {
    }

    // Every open pull request with a stored round, changed or not, because a write skipped for a busy lock or a read that failed is repaired only by a later pass over a listing that may name nothing new; the review rounds are asked and not the watcher's own, so a round queued through a review the watcher did not start is mirrored too.
    public function reconcileOpenOf(string $project): void
    {
        foreach ($this->find->openOf($project) as $change) {
            $this->reconcileOne($project, $change);
        }
    }

    private function reconcileOne(string $project, OpenChange $change): void
    {
        try {
            if ($this->rounds->of($project, $change->id->value) instanceof ReviewStatus) {
                $this->status->reconcile($project, $change->id);
            }
            // Boundary: one pull request whose round cannot be read must not keep the others from their labels, nor hide the failure of the head step this pass runs after.
        } catch (Throwable $failure) {
            $this->logger->warning('The status label of {change} of {project} was not reconciled, its round could not be read: {reason}', ['change' => $change->id->value, 'project' => $project, 'reason' => $failure->getMessage()]);
        }
    }
}
