<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\WorkSource\Contract\HeldTask;
use Intakt\Plugin\WorkSource\Contract\NothingPicked;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\WorkerState;
use Intakt\Plugin\WorkSource\Contract\WorkPick;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Plugin\WorkSource\Contract\WorkSource;
use Psr\Log\LoggerInterface;

final readonly class GitHubWorkSource implements WorkSource
{
    public function __construct(
        private CompleteListing $listing,
        private IssueHistories $histories,
        private SteeringAccounts $accounts,
        private IntakeStore $store,
        private LoggerInterface $logger,
    ) {
    }

    // The worker's state is asked before anything else, because a worker that will not take work this tick must cost no forge call.
    public function nextWork(string $project, WorkerState $worker): WorkPick
    {
        if (!$worker->takesWork()) {
            return WorkPick::nothing(NothingPicked::WorkerNotTakingWork);
        }

        $plan = $this->listing->planOf($project);
        if (!$plan instanceof IntakePlan) {
            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }

        return $this->firstStillPickable($project, $plan);
    }

    public function queue(string $project): WorkQueue
    {
        $plan = $this->listing->planOf($project);
        if (!$plan instanceof IntakePlan) {
            return WorkQueue::incomplete();
        }

        return WorkQueue::listed(
            array_map(fn (QueuedIssue $issue): Task => $this->taskOf($issue, $plan), $plan->ready),
            array_map(fn (HeldIssue $held): HeldTask => new HeldTask($this->taskOf($held->issue, $plan), $held->reason), $plan->held),
        );
    }

    public function failedForGood(string $project, int $number, string $reason): void
    {
        $this->store->failForGood($project, $number, $reason);
    }

    // A check that failed ends the tick, because skipping that task would pick one that sorts behind it.
    private function firstStillPickable(string $project, IntakePlan $plan): WorkPick
    {
        foreach ($plan->ready as $issue) {
            try {
                $steered = $this->isStillSteered($project, $issue);
            } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
                $this->logger->warning('The write-access check of task #{number} of project {project} failed, so nothing is picked: {reason}', ['number' => $issue->number(), 'project' => $project, 'reason' => $failure->getMessage(), 'exception' => $failure]);

                return WorkPick::nothing(NothingPicked::TrustNotChecked);
            }

            if (!$steered) {
                $this->logger->info('Task #{number} of project {project} is not picked: its author or last editor has no write access now.', ['number' => $issue->number(), 'project' => $project]);

                continue;
            }

            if (!$this->isHeldByAFailure($project, $issue)) {
                return WorkPick::picked($this->taskOf($issue, $plan));
            }
        }

        return WorkPick::nothing(NothingPicked::NoTaskReady);
    }

    // Asked again before each pick, because access may have been withdrawn since the item was vouched.
    private function isStillSteered(string $project, QueuedIssue $issue): bool
    {
        if (!$this->accounts->steers($project, $issue->author())) {
            return false;
        }

        $history = $this->histories->of($project, $issue->number(), $issue->updatedAt());

        return $this->accounts->steers($project, $history->lastEditor);
    }

    // Read under the lock a failure is recorded with, so a task marked failed after the plan was made is never picked.
    private function isHeldByAFailure(string $project, QueuedIssue $issue): bool
    {
        $failures = $this->store->failuresUnderTheListingLock($project);

        return isset($failures[$issue->number()]) || array_any($issue->waitsFor(), static fn (int $target): bool => isset($failures[$target]));
    }

    private function taskOf(QueuedIssue $issue, IntakePlan $plan): Task
    {
        return new Task($issue->number(), $issue->title(), $plan->pullRequests[$issue->number()] ?? null);
    }
}
