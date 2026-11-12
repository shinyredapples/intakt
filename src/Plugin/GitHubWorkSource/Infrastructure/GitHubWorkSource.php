<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
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
        private IntakeSnapshots $snapshots,
        private EligibilityLabels $labels,
        private SteeringAccounts $accounts,
        private LoggerInterface $logger,
    ) {
    }

    public function nextWork(string $project, WorkerState $worker): WorkPick
    {
        if (!$worker->takesWork()) {
            return WorkPick::nothing(NothingPicked::WorkerNotTakingWork);
        }

        $items = $this->itemsOf($project);
        if (null === $items) {
            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }

        try {
            return $this->firstPickable($project, $items);
        } catch (TrustNotChecked $failure) {
            $this->logger->error('Write access of a task writer could not be checked, so no task is picked.', ['project' => $project, 'exception' => $failure]);

            return WorkPick::nothing(NothingPicked::TrustNotChecked);
        }
    }

    public function queue(string $project): WorkQueue
    {
        $items = $this->itemsOf($project);
        if (null === $items) {
            return WorkQueue::incomplete();
        }

        $plan = $this->planOf($project, $items);

        return WorkQueue::listed(
            array_map(fn (QueuedIssue $task): Task => $this->taskOf($task, $plan), $plan->ready),
            array_map(fn (HeldIssue $held): HeldTask => new HeldTask($this->taskOf($held->issue, $plan), $held->reason), $plan->held),
        );
    }

    public function failedForGood(string $project, int $number, string $reason): void
    {
        $this->snapshots->failForGood($project, $number, $reason);
    }

    /**
     * @return list<QueuedIssue>|null
     */
    private function itemsOf(string $project): ?array
    {
        try {
            return $this->snapshots->current($project);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            // Nothing is picked and no wait is closed, because an order over part of the listing would put the wrong task first.
            $this->logger->error('The open issues of the project were not listed completely, so no task is picked.', ['project' => $project, 'exception' => $failure]);

            return null;
        }
    }

    /**
     * @param list<QueuedIssue> $items
     */
    private function planOf(string $project, array $items): IntakePlan
    {
        return new IntakeRules($this->labels->of($project))->planOf($items, $this->snapshots->failuresOf($project));
    }

    /**
     * @param list<QueuedIssue> $items
     *
     * @throws TrustNotChecked
     */
    private function firstPickable(string $project, array $items): WorkPick
    {
        foreach ($this->planOf($project, $items)->ready as $task) {
            // Asked again before each build, because write access can be taken away after the task became eligible.
            if (!$this->accounts->steers($project, $task->author()) || !$this->accounts->steers($project, $task->editor() ?? $task->author())) {
                $this->logger->warning('A task writer lost write access, so the task is skipped.', ['project' => $project, 'task' => $task->number()]);

                continue;
            }

            // Planned again after the checks, because a failure another worker recorded since must keep this task, and the tasks that wait on it, from the pick.
            $now = $this->planOf($project, $items);
            if (in_array($task, $now->ready, true)) {
                return WorkPick::picked($this->taskOf($task, $now));
            }
        }

        return WorkPick::nothing(NothingPicked::NoTaskReady);
    }

    private function taskOf(QueuedIssue $issue, IntakePlan $plan): Task
    {
        return new Task($issue->number(), $issue->title(), $plan->pullRequests[$issue->number()] ?? null);
    }
}
