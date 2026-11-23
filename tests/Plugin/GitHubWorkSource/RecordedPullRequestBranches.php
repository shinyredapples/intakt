<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\PullRequestBranches;

// Lists every pull request the issue listing was last scripted with, on a branch that names no task unless a test scripts one, and counts every read, because a test must see that the branches are read on each call.
final class RecordedPullRequestBranches implements PullRequestBranches
{
    public int $reads = 0;
    /** @var array<int, string> */
    public array $branchOf = [];
    /** @var list<int> */
    public array $leftOut = [];
    public ?ForgeReadFailed $failure = null;

    public function __construct(
        private readonly RecordedIssueReads $issues,
    ) {
    }

    public function openOf(string $project): array
    {
        ++$this->reads;
        if ($this->failure instanceof ForgeReadFailed) {
            throw $this->failure;
        }

        $pulls = array_filter($this->issues->lastListed, fn (ListedIssue $issue): bool => $issue->isPullRequest && !in_array($issue->number, $this->leftOut, true));
        ksort($pulls);

        return array_values(array_map(fn (ListedIssue $pull): BranchPullRequest => new BranchPullRequest($pull->number, $this->branchOf[$pull->number] ?? 'chore/tidy-up', $pull->author, $pull->updatedAt), $pulls));
    }
}
