<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

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

        // In finally, because a failing head must not keep the labels of the other pull requests from being repaired; it reads the last completed listing, so a running listing does not matter.
        try {
            $this->lookAtHeads($project);
        } finally {
            $this->mirrors->reconcileOpenOf($project->name);
        }
    }

    private function lookAtHeads(WatchedProject $project): void
    {
        try {
            $this->changed->enqueue($project->name);
        } catch (ListingInProgress) {
            // Skipped because the running listing reports for itself and the next tick comes soon.
            return;
        } catch (ForgeRateLimited) {
            // Not ended here, because the CI reads of the queued heads are essential and the forge answers them from its reserve; one the forge's own pause refuses makes its head wait for the reset in HeadBackOff.
        }

        $this->heads->lookAtDue($project);
    }
}
