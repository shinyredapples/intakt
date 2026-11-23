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

                return $history instanceof IssueHistory && $this->belongsTo($task->number(), $pull, $history, $plan);
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
        $missing = array_diff(array_keys($plan->openPullRequests), array_map(static fn (BranchPullRequest $pull): int => $pull->number, $open));
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

    // Every pull request the plan holds as closing the task, every one on its branch and every one updated since the tick's listing, lowest number first, so the first that counts is the task's pull request, and a higher one stands in when a lower one lost its trust. An updated one may close the task by an edit the plan has not seen, so only its body read now can tell. The closing ones are always among the open ones, because the listing's open pull requests were checked against them.
    /**
     * @param list<BranchPullRequest> $open
     *
     * @return list<BranchPullRequest>
     */
    private function candidatesOf(int $task, IntakePlan $plan, array $open): array
    {
        $closing = $plan->closingPullRequests[$task] ?? [];
        $candidates = array_values(array_filter($open, static fn (BranchPullRequest $pull): bool => in_array($pull->number, $closing, true) || TaskBranchName::names($pull->branch, $task) || self::updatedSinceListing($pull, $plan)));
        usort($candidates, static fn (BranchPullRequest $a, BranchPullRequest $b): int => $a->number <=> $b->number);

        return $candidates;
    }

    private static function updatedSinceListing(BranchPullRequest $pull, IntakePlan $plan): bool
    {
        $listedAt = $plan->openPullRequests[$pull->number] ?? null;

        return null === $listedAt || $listedAt->getTimestamp() !== $pull->updatedAt->getTimestamp();
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

        return Vouching::of($pull->author, $history, $steers)->trusted ? $history : null;
    }

    // A trusted pull request that is not on the task's branch must close the task in the history just read. One the plan gives to the task and that no longer closes it fails the read, because a trusted edit between the tick's listing and the branch read pointed it elsewhere and the next listing settles where; one the plan does not give to the task is simply another task's.
    private function belongsTo(int $task, BranchPullRequest $pull, IssueHistory $history, IntakePlan $plan): bool
    {
        if (TaskBranchName::names($pull->branch, $task) || in_array($task, new IssueReferences()->closedBy($history->body), true)) {
            return true;
        }

        if (in_array($pull->number, $plan->closingPullRequests[$task] ?? [], true)) {
            throw new ForgeReadFailed(sprintf('GitHub holds pull request #%d without the closing reference to #%d it was listed with, so the pull request of that task is not known until the next listing.', $pull->number, $task));
        }

        return false;
    }
}
