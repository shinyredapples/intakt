<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;

#[AsCommandHandler]
final readonly class WatchPullRequestsHandler
{
    public function __construct(
        private WatchedProjects $projects,
        private FindChangedPullRequests $find,
        private PendingHeads $pending,
        private StaleVerdicts $stale,
        private AdmitHeads $admit,
        private RoundStarts $starts,
        private WatcherLedger $ledger,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     */
    public function __invoke(WatchPullRequests $tick): void
    {
        $project = $this->projects->named($tick->project);

        try {
            $toLookAt = $this->changedAndDue($project->name);
        } catch (ListingInProgress) {
            // The listing in progress reports its own result, and the next tick comes soon.
            return;
        }

        array_walk($toLookAt, fn (OpenChange $open) => $this->stale->noteMovedHead($project, $open));

        foreach (($this->admit)($project->name, $toLookAt) as $decision) {
            $this->settle($project, $decision);
        }
    }

    /**
     * @return list<OpenChange>
     *
     * @throws ListingInProgress
     */
    private function changedAndDue(string $project): array
    {
        $changed = ($this->find)($project);
        $changedIds = array_map(static fn (OpenChange $open): string => $open->id->value, $changed);
        $due = array_filter($this->pending->dueFor($project), static fn (OpenChange $open): bool => !in_array($open->id->value, $changedIds, true));

        return [...$changed, ...$due];
    }

    private function settle(WatchedProject $project, HeadDecision $decision): void
    {
        $this->ledger->headDecided($project->name, $decision);

        if (HeadAdmission::Pending === $decision->admission) {
            $this->pending->keep($project->name, $decision);

            return;
        }

        $this->pending->forget($project->name, $decision->change);

        if ($decision->startsRound()) {
            $this->starts->startFor($project, $decision);
        }
    }
}
