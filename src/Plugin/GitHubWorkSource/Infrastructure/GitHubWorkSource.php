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

        $plan = $this->planOf($project);
        if (!$plan instanceof IntakePlan) {
            return WorkPick::nothing(NothingPicked::ListingIncomplete);
        }

        try {
            return $this->firstStillTrusted($project, $plan->ready);
        } catch (TrustNotChecked $failure) {
            $this->logger->error('Write access of a task writer could not be checked, so no task is picked.', ['project' => $project, 'exception' => $failure]);

            return WorkPick::nothing(NothingPicked::TrustNotChecked);
        }
    }

    public function queue(string $project): WorkQueue
    {
        $plan = $this->planOf($project);
        if (!$plan instanceof IntakePlan) {
            return WorkQueue::incomplete();
        }

        return WorkQueue::listed(
            array_map($this->taskOf(...), $plan->ready),
            array_map(fn (HeldIssue $held): HeldTask => new HeldTask($this->taskOf($held->issue), $held->reason), $plan->held),
        );
    }

    public function failedForGood(string $project, int $number, string $reason): void
    {
        $this->snapshots->failForGood($project, $number, $reason);
    }

    private function planOf(string $project): ?IntakePlan
    {
        try {
            $items = $this->snapshots->current($project);
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            // Nothing is picked and no wait is closed, because an order over part of the listing would put the wrong task first.
            $this->logger->error('The open issues of the project were not listed completely, so no task is picked.', ['project' => $project, 'exception' => $failure]);

            return null;
        }

        return new IntakeRules($this->labels->of($project))->planOf($items);
    }

    /**
     * @param list<QueuedIssue> $ready
     *
     * @throws TrustNotChecked
     */
    private function firstStillTrusted(string $project, array $ready): WorkPick
    {
        foreach ($ready as $task) {
            // Asked again before each build, because write access can be taken away after the task became eligible.
            if ($this->accounts->steers($project, $task->author()) && $this->accounts->steers($project, $task->editor() ?? $task->author())) {
                return WorkPick::picked($this->taskOf($task));
            }

            $this->logger->warning('A task writer lost write access, so the task is skipped.', ['project' => $project, 'task' => $task->number()]);
        }

        return WorkPick::nothing(NothingPicked::NoTaskReady);
    }

    private function taskOf(QueuedIssue $issue): Task
    {
        return new Task($issue->number(), $issue->title());
    }
}
