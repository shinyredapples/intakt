<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\PullRequestBranches;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskBranchName;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;

// The branches are read on every call and never kept, because a pull request opened on a task's branch since the last tick makes the task a rework of it, never a fresh build beside it.
final readonly class PullRequestsOfTasks
{
    public function __construct(
        private PullRequestBranches $branches,
        private IssueHistories $histories,
        private SteeringAccounts $accounts,
        private IssueReferences $references,
    ) {
    }

    // The lowest open pull request of each task that has one, found by closing keyword (every one the plan holds) or by branch, and only one whose author and last editor have write access now, because anyone can open a pull request on a branch named after a task, and a plan kept from an unchanged listing does not see access withdrawn since.
    /**
     * @param list<QueuedIssue> $tasks
     *
     * @return array<int, int>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     * @throws TrustNotChecked
     */
    public function reworksOf(string $project, IntakePlan $plan, array $tasks): array
    {
        $open = $this->allOpenOf($project, $plan);
        $historyOf = $this->trustedHistoryReadOncePerPullRequest($project);
        $reworks = [];
        foreach ($tasks as $task) {
            $found = array_find($this->candidatesOf($task->number(), $plan, $open), function (BranchPullRequest $pull) use ($historyOf, $plan, $task): bool {
                $history = $historyOf($pull);

                return $history instanceof IssueHistory && ($this->closesNow($task->number(), $pull, $history) || $this->notTheTasks($task->number(), $pull, $plan));
            });
            if ($found instanceof BranchPullRequest) {
                $reworks[$task->number()] = $found->number;
            }
        }

        return $reworks;
    }

    // Checked against the tick's complete listing, because pages that shift while they are read can skip a pull request, and a skipped one would let its task be built afresh.
    /**
     * @return list<BranchPullRequest>
     */
    private function allOpenOf(string $project, IntakePlan $plan): array
    {
        $open = $this->branches->openOf($project);
        $missing = array_diff($plan->openPullRequests, array_map(static fn (BranchPullRequest $pull): int => $pull->number, $open));
        if ([] !== $missing) {
            throw new ForgeReadFailed(sprintf('GitHub did not list the branch of open pull request #%d, which the listing of this tick shows open, so the pull requests by branch may be incomplete.', min($missing)));
        }

        return $open;
    }

    // Remembered for one call only, because a pull request is a candidate of every task it may close, the author of a pull request is often its last editor, and each read or ask is a forge call.
    /**
     * @return Closure(BranchPullRequest): ?IssueHistory
     */
    private function trustedHistoryReadOncePerPullRequest(string $project): Closure
    {
        $answers = [];
        $steers = function (string $account) use ($project, &$answers): bool {
            return $answers[$account] ??= $this->accounts->steers($project, $account);
        };
        $histories = [];

        return function (BranchPullRequest $pull) use ($project, $steers, &$histories): ?IssueHistory {
            if (!array_key_exists($pull->number, $histories)) {
                $histories[$pull->number] = $this->trustedHistoryOf($project, $pull, $steers);
            }

            return $histories[$pull->number];
        };
    }

    // Every pull request the plan holds as closing the task, every one on its branch and every one whose body in the branch listing closes it, lowest number first, so the first that counts is the task's pull request, and a higher one stands in when a lower one lost its trust. The body decides and not the update time, because the branch listing is read after the tick's listing and an edit in the second of that listing keeps the update time. The closing ones are always among the open ones, because the listing's open pull requests were checked against them.
    /**
     * @param list<BranchPullRequest> $open
     *
     * @return list<BranchPullRequest>
     */
    private function candidatesOf(int $task, IntakePlan $plan, array $open): array
    {
        $closing = $plan->closingPullRequests[$task] ?? [];
        $candidates = array_values(array_filter($open, fn (BranchPullRequest $pull): bool => in_array($pull->number, $closing, true) || TaskBranchName::names($pull->branch, $task) || in_array($task, $this->references->closedBy($pull->body), true)));
        usort($candidates, static fn (BranchPullRequest $a, BranchPullRequest $b): int => $a->number <=> $b->number);

        return $candidates;
    }

    // The author is asked first, because the history of one whose author has no write access need not be read; its history is the one the branch listing showed, so an edit since fails the read instead of passing unchecked.
    /**
     * @param Closure(string): bool $steers
     */
    private function trustedHistoryOf(string $project, BranchPullRequest $pull, Closure $steers): ?IssueHistory
    {
        if (!$steers($pull->author)) {
            return null;
        }

        $history = $this->histories->of($project, $pull->number, $pull->updatedAt);
        if (!Vouching::of($pull->author, $history, $steers)->trusted) {
            return null;
        }

        return $this->sameBodyAsListed($pull, $history);
    }

    // The update times compare whole seconds, so an edit in the second of the branch listing passes the history read; its body may close a task the listed body did not make a candidate, so that edit fails the read like any other since the listing.
    private function sameBodyAsListed(BranchPullRequest $pull, IssueHistory $history): IssueHistory
    {
        if ($history->body !== $pull->body) {
            throw new ForgeReadFailed(sprintf('GitHub changed the body of pull request #%d after the branch listing, so the tasks it closes are not known until the next listing.', $pull->number));
        }

        return $history;
    }

    // A trusted pull request that is not on the task's branch must close the task in the history just read, not only in the plan, because a trusted edit between the tick's listing and the branch read can point it at another task.
    private function closesNow(int $task, BranchPullRequest $pull, IssueHistory $history): bool
    {
        return TaskBranchName::names($pull->branch, $task) || in_array($task, $this->references->closedBy($history->body), true);
    }

    // One the plan gives to the task and that no longer closes it fails the read, because the next listing settles which task it closes now; one the plan does not give to the task is simply another task's.
    private function notTheTasks(int $task, BranchPullRequest $pull, IntakePlan $plan): bool
    {
        if (in_array($pull->number, $plan->closingPullRequests[$task] ?? [], true)) {
            throw new ForgeReadFailed(sprintf('GitHub holds pull request #%d without the closing reference to #%d it was listed with, so the pull request of that task is not known until the next listing.', $pull->number, $task));
        }

        return false;
    }
}
