<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\PullRequestBranches;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
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
    ) {
    }

    // The lowest open pull request of each task that has one, found by closing keyword (from the plan) or by branch, and only one whose author and last editor have write access now, because anyone can open a pull request on a branch named after a task, and a plan kept from an unchanged listing does not see access withdrawn since.
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
        $steers = $this->accessAskedOncePerAccount($project);
        $reworks = [];
        foreach ($tasks as $task) {
            $found = array_find($this->candidatesOf($task->number(), $plan, $open), fn (BranchPullRequest $pull): bool => $this->isTrusted($project, $pull, $steers));
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

    // Remembered for one call only, because the author of a pull request is often its last editor, and each ask is a forge call.
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

    // Lowest number first, so the first trusted one is the task's pull request. The closing one is always among the open ones, because the listing's open pull requests were checked against them.
    /**
     * @param list<BranchPullRequest> $open
     *
     * @return list<BranchPullRequest>
     */
    private function candidatesOf(int $task, IntakePlan $plan, array $open): array
    {
        $closing = $plan->pullRequests[$task] ?? null;
        $candidates = array_values(array_filter($open, static fn (BranchPullRequest $pull): bool => $pull->number === $closing || TaskBranchName::names($pull->branch, $task)));
        usort($candidates, static fn (BranchPullRequest $a, BranchPullRequest $b): int => $a->number <=> $b->number);

        return $candidates;
    }

    // The author is asked first, because a pull request whose author has no write access counts for nothing and its history need not be read; its history is the one it was listed with, so an edit since fails the read instead of passing unchecked.
    /**
     * @param Closure(string): bool $steers
     */
    private function isTrusted(string $project, BranchPullRequest $pull, Closure $steers): bool
    {
        if (!$steers($pull->author)) {
            return false;
        }

        return Vouching::of($pull->author, $this->histories->of($project, $pull->number, $pull->updatedAt), $steers)->trusted;
    }
}
