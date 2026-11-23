<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
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
        private IssueReferences $references,
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

            $pick = $this->pickUnlessFailed($project, $issue, $plan);
            if ($pick instanceof WorkPick) {
                return $pick;
            }
        }

        return WorkPick::nothing(NothingPicked::NoTaskReady);
    }

    // Asked again before each pick, because access may have been withdrawn since the item was vouched. The history must still be of the planned item, because an edit in the second of the listing keeps the update time and may add a wait or change a label the plan does not hold. Its labels are vouched again too, because a label removed and applied again in that second by an account without write access leaves the label set as planned.
    private function isStillSteered(string $project, QueuedIssue $issue): bool
    {
        $steers = $this->accessAskedOncePerAccount($project);
        if (!$steers($issue->author())) {
            return false;
        }

        $history = $this->histories->of($project, $issue->number(), $issue->updatedAt());
        if (!$issue->isListedAs($history, $this->references)) {
            throw new ForgeReadFailed(sprintf('GitHub holds #%d with other labels, waits or closing references than it was planned with, so its history is not of the planned item.', $issue->number()));
        }

        $vouching = Vouching::of($issue->author(), $history, $steers);
        if (!$vouching->trusted) {
            return false;
        }

        if (!$issue->isVouchedAs($vouching)) {
            throw new ForgeReadFailed(sprintf('The labels of #%d are no longer applied by the accounts with write access the plan counted, so it is not the planned item.', $issue->number()));
        }

        return true;
    }

    // Remembered for one pick only, because the author is often the last editor and the one who applied the labels, and each ask is a forge call.
    /**
     * @return Closure(string): bool
     */
    private function accessAskedOncePerAccount(string $project): Closure
    {
        $answers = [];

        return function (string $account) use ($project, &$answers): bool {
            return $answers[$account] ??= $this->accounts->steers($project, $account);
        };
    }

    // Null when the task failed for good, so the next one is tried; a refused read ends the tick, because a failure may be stored that it did not see.
    private function pickUnlessFailed(string $project, QueuedIssue $issue, IntakePlan $plan): ?WorkPick
    {
        try {
            return $this->isHeldByAFailure($project, $issue, $plan) ? null : WorkPick::picked($this->taskOf($issue, $plan));
        } catch (ProjectHeldByAnotherWriter $held) {
            $this->logger->warning('The failures of project {project} could not be read before task #{number} is picked, so nothing is picked this tick: {reason}', ['number' => $issue->number(), 'project' => $project, 'reason' => $held->getMessage(), 'exception' => $held]);

            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }
    }

    // Read under the lock a failure is recorded with, so a task marked failed after the plan was made is never picked. Its waits are the ones the plan resolved, because a wait on a pull request stands for the tasks that pull request closes.
    private function isHeldByAFailure(string $project, QueuedIssue $issue, IntakePlan $plan): bool
    {
        $failures = $this->store->failuresUnderTheListingLock($project);

        return isset($failures[$issue->number()]) || array_any($plan->dependsOn[$issue->number()] ?? [], static fn (int $target): bool => isset($failures[$target]));
    }

    private function taskOf(QueuedIssue $issue, IntakePlan $plan): Task
    {
        return new Task($issue->number(), $issue->title(), $plan->pullRequests[$issue->number()] ?? null);
    }
}
