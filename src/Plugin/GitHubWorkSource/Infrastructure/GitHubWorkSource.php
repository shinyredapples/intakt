<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemNotAsPlanned;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\QueueOrder;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\WorkSource\Contract\HeldTask;
use Intakt\Plugin\WorkSource\Contract\Holder;
use Intakt\Plugin\WorkSource\Contract\NothingPicked;
use Intakt\Plugin\WorkSource\Contract\OpenTask;
use Intakt\Plugin\WorkSource\Contract\OpenWork;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\TaskPriority;
use Intakt\Plugin\WorkSource\Contract\Wait;
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
        private PullRequestsOfTasks $pullRequests,
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

        if ([] === $plan->ready) {
            return WorkPick::nothing(NothingPicked::NoTaskReady);
        }

        $reworks = $this->reworksOrNone($project, $plan, $plan->ready);
        if (null === $reworks) {
            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }

        return $this->firstStillPickable($project, $plan, $reworks);
    }

    public function queue(string $project): WorkQueue
    {
        $plan = $this->listing->planOf($project);
        if (!$plan instanceof IntakePlan) {
            return WorkQueue::incomplete();
        }

        $reworks = $this->reworksOrNone($project, $plan, [...$plan->ready, ...array_map(static fn (HeldIssue $held): QueuedIssue => $held->issue, $plan->held)]);
        if (null === $reworks) {
            return WorkQueue::incomplete();
        }

        return WorkQueue::listed(
            array_map(fn (QueuedIssue $issue): Task => $this->taskOf($issue, $reworks), QueueOrder::of($plan->ready, $reworks)),
            array_map(fn (HeldIssue $held): HeldTask => new HeldTask($this->taskOf($held->issue, $reworks), $held->reason), $plan->held),
        );
    }

    // Taken from the plan, because its tasks are the eligible ones written and last edited by an account with write access, so each wait line in their text is trusted. Milestones without an open item are not in the stored rows, so none is reported empty.
    public function openWork(string $project): ?OpenWork
    {
        $plan = $this->listing->planOf($project);
        if (!$plan instanceof IntakePlan) {
            return null;
        }

        $issues = [...$plan->ready, ...array_map(static fn (HeldIssue $held): QueuedIssue => $held->issue, $plan->held)];

        return new OpenWork(array_map($this->openTaskOf(...), $issues), []);
    }

    public function failedForGood(string $project, int $number, string $reason): void
    {
        $this->store->failForGood($project, $number, $reason);
    }

    // Nothing rather than reworks left out, because a task taken for a fresh build may have a pull request open, and the order depends on it.
    /**
     * @param list<QueuedIssue> $tasks
     *
     * @return array<int, int>|null
     */
    private function reworksOrNone(string $project, IntakePlan $plan, array $tasks): ?array
    {
        try {
            return $this->pullRequests->reworksOf($project, $plan, $tasks);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            $this->logger->warning('The open pull requests of the tasks of project {project} could not be read, so nothing is picked or shown: {reason}', ['project' => $project, 'reason' => $failure->getMessage(), 'exception' => $failure]);

            return null;
        }
    }

    // The order is taken from the reworks read now, because a pull request found only by its branch is not in the plan's order.
    /**
     * @param array<int, int> $reworks
     */
    private function firstStillPickable(string $project, IntakePlan $plan, array $reworks): WorkPick
    {
        foreach (QueueOrder::of($plan->ready, $reworks) as $issue) {
            $steered = $this->stillSteeredOrEndOfTick($project, $issue);
            if ($steered instanceof WorkPick) {
                return $steered;
            }

            if (!$steered) {
                $this->logger->info('Task #{number} of project {project} is not picked: its author or last editor has no write access now.', ['number' => $issue->number(), 'project' => $project]);

                continue;
            }

            $pick = $this->pickWithRework($project, $issue, $plan);
            if ($pick instanceof WorkPick) {
                return $pick;
            }
        }

        return WorkPick::nothing(NothingPicked::NoTaskReady);
    }

    // A check that failed ends the tick, because skipping that task would pick one that sorts behind it.
    private function stillSteeredOrEndOfTick(string $project, QueuedIssue $issue): bool|WorkPick
    {
        try {
            return $this->isStillSteered($project, $issue);
        } catch (ItemNotAsPlanned $changed) {
            return $this->readAgainOnTheNextListing($project, $issue, $changed);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            $this->logger->warning('The write-access check of task #{number} of project {project} failed, so nothing is picked: {reason}', ['number' => $issue->number(), 'project' => $project, 'reason' => $failure->getMessage(), 'exception' => $failure]);

            return WorkPick::nothing(NothingPicked::TrustNotChecked);
        }
    }

    // Read again after the steering check, because a pull request opened on the task's branch during it makes the task a rework, never a fresh build.
    private function pickWithRework(string $project, QueuedIssue $issue, IntakePlan $plan): ?WorkPick
    {
        $now = $this->reworksOrNone($project, $plan, [$issue]);
        if (null === $now) {
            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }

        return $this->pickUnlessFailed($project, $this->taskOf($issue, $now), $plan);
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
            throw ItemNotAsPlanned::listedOtherwise($issue->number());
        }

        $vouching = Vouching::of($issue->author(), $history, $steers);
        if (!$vouching->trusted) {
            return false;
        }

        if (!$issue->isVouchedAs($vouching)) {
            throw ItemNotAsPlanned::vouchedOtherwise($issue->number());
        }

        return true;
    }

    // Nothing is picked even once the item is forgotten, because a task behind it would be picked out of order; a refused forget is only logged, because the tick is fail-closed either way.
    private function readAgainOnTheNextListing(string $project, QueuedIssue $issue, ItemNotAsPlanned $changed): WorkPick
    {
        $this->logger->warning('Task #{number} of project {project} is not the item it was planned with, so nothing is picked and the next listing reads it again: {reason}', ['number' => $issue->number(), 'project' => $project, 'reason' => $changed->getMessage(), 'exception' => $changed]);
        try {
            $this->store->forgetVouchingOf($project, $issue->number());
        } catch (ProjectHeldByAnotherWriter $held) {
            $this->logger->warning('Task #{number} of project {project} could not be forgotten, so the next listing may plan it as before: {reason}', ['number' => $issue->number(), 'project' => $project, 'reason' => $held->getMessage(), 'exception' => $held]);
        }

        return WorkPick::nothing(NothingPicked::TrustNotChecked);
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

    // Null when the task failed for good, so the next one is tried; a refused read ends the tick, because a failure may be stored that it did not see. The pull requests were read before the failures, so a failure recorded during that forge read is still seen.
    private function pickUnlessFailed(string $project, Task $task, IntakePlan $plan): ?WorkPick
    {
        try {
            return $this->isHeldByAFailure($project, $task->number, $plan) ? null : WorkPick::picked($task);
        } catch (ProjectHeldByAnotherWriter $held) {
            $this->logger->warning('The failures of project {project} could not be read before task #{number} is picked, so nothing is picked this tick: {reason}', ['number' => $task->number, 'project' => $project, 'reason' => $held->getMessage(), 'exception' => $held]);

            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }
    }

    // Read under the lock a failure is recorded with, so a task marked failed after the plan was made is never picked. Its waits are the ones the plan resolved, because a wait on a pull request stands for the tasks that pull request closes.
    private function isHeldByAFailure(string $project, int $number, IntakePlan $plan): bool
    {
        $failures = $this->store->failuresUnderTheListingLock($project);

        return isset($failures[$number]) || array_any($plan->dependsOn[$number] ?? [], static fn (int $target): bool => isset($failures[$target]));
    }

    /**
     * @param array<int, int> $reworks
     */
    private function taskOf(QueuedIssue $issue, array $reworks): Task
    {
        return new Task($issue->number(), $issue->title(), $reworks[$issue->number()] ?? null);
    }

    // The holder comes from status labels an account with write access applied last, because Intakt's mirror of a row is what says who it waits on.
    private function openTaskOf(QueuedIssue $issue): OpenTask
    {
        $holder = match (true) {
            $issue->hasVouchedLabel(WorkLabels::STATUS_WAITING_ON_YOU) => Holder::Person,
            $issue->hasVouchedLabel(WorkLabels::STATUS_PARKED) => Holder::Session,
            default => Holder::Nothing,
        };

        return new OpenTask(
            $issue->number(),
            $issue->title(),
            TaskPriority::from($issue->priority()->value),
            $issue->milestoneDue(),
            $holder,
            array_map(static fn (int $target): Wait => new Wait($target, $issue->isTrusted()), $issue->waitsFor()),
        );
    }
}
