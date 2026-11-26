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

// Answers the reads intakt:queue:show makes after the listing from the recording the test environment's IssueReads serves, so the command runs on an install without GitHub: every account has write access, every item was last edited and labeled by its author, and a pull request is on the branch its recorded `head.ref` names, or on no task branch when it has none, so the labels, the waits, the closing keywords and those branches steer the queue.
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

        $branches = $this->branchesOfTheRecording();

        return array_values(array_map(static fn (ListedIssue $pull): BranchPullRequest => new BranchPullRequest($pull->number, $branches[$pull->number] ?? self::NO_TASK_BRANCH, $pull->author, $pull->updatedAt, $pull->body), $pulls));
    }

    /**
     * @return array<int, string>
     */
    private function branchesOfTheRecording(): array
    {
        $file = $_SERVER['BUILD_RECORDING'] ?? $_ENV['BUILD_RECORDING'] ?? null;
        if (!is_string($file) || !is_file($file)) {
            return [];
        }

        $branches = [];
        /** @var list<array{number: int, head?: array{ref: string}}> $items */
        $items = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach ($items as $item) {
            if (isset($item['head']['ref'])) {
                $branches[$item['number']] = $item['head']['ref'];
            }
        }

        return $branches;
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
