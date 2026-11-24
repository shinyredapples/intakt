<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\PullRequestBranches;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;

// Answers the reads intakt:queue:show makes after the listing from the recording the test environment's IssueReads serves, so the command runs on an install without GitHub: every account has write access, every item was last edited and labeled by its author, and no pull request is on a task branch, so only the labels, the waits and the closing keywords steer the queue.
final readonly class RecordedQueueAnswers implements IssueHistories, SteeringAccounts, PullRequestBranches
{
    private const string NO_TASK_BRANCH = 'recorded/no-task';

    public function __construct(
        private IssueReads $issues,
    ) {
    }

    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory
    {
        $item = $this->listed($project)[$number] ?? throw new ForgeReadFailed(sprintf('The recording holds no #%d.', $number));

        return new IssueHistory($item->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, $item->author), [...$item->labels]), $item->body, $item->labels);
    }

    public function steers(string $project, string $account): bool
    {
        return true;
    }

    public function openOf(string $project): array
    {
        $pulls = array_filter($this->listed($project), static fn (ListedIssue $item): bool => $item->isPullRequest);
        ksort($pulls);

        return array_values(array_map(static fn (ListedIssue $pull): BranchPullRequest => new BranchPullRequest($pull->number, self::NO_TASK_BRANCH, $pull->author, $pull->updatedAt, $pull->body), $pulls));
    }

    /**
     * @return array<int, ListedIssue>
     */
    private function listed(string $project): array
    {
        $listed = [];
        foreach ($this->issues->listOpen($project, null)->issues() as $item) {
            $listed[$item->number] = $item;
        }

        return $listed;
    }
}
