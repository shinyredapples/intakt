<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Throwable;

#[AsCommandHandler]
final readonly class WatchPullRequestsHandler
{
    public function __construct(
        private WatchedProjects $projects,
        private ChangedHeads $changed,
        private LookAtHeads $heads,
        private StatusMirrors $mirrors,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     * @throws WatcherEventNotRecorded
     */
    public function __invoke(WatchPullRequests $tick): void
    {
        $project = $this->projects->named($tick->project);

        try {
            $this->lookAtChangedAndDue($project);
        } catch (ListingInProgress) {
            // Skipped because the running listing reports for itself and the next tick comes soon.
            return;
            // Boundary: reconciled before any failure of the tick is rethrown, because the stored listing still names the open pull requests, and a label a busy lock or a failed read left wrong must not wait for as long as the listing or a head step keeps failing.
        } catch (Throwable $failed) {
            $this->mirrors->reconcileAfter($failed, $project->name);

            throw $failed;
        }

        $this->mirrors->reconcileEveryOpenWithRound($project->name);
    }

    /**
     * @throws ListingInProgress
     * @throws ForgeReadFailed
     * @throws WatcherEventNotRecorded
     */
    private function lookAtChangedAndDue(WatchedProject $project): void
    {
        try {
            $this->changed->enqueue($project->name);
        } catch (ForgeRateLimited) {
            // Not ended here, because the CI reads of the queued heads are essential and the forge answers them from its reserve; one the forge's own pause refuses makes its head wait for the reset in HeadBackOff.
        }

        $this->heads->lookAtDue($project);
    }
}
