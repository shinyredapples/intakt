<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// Answers the next scripted listing and counts every read, because a test must see which reads a tick made.
final class RecordedIssueReads implements IssueReads
{
    public int $listings = 0;
    public int $historyReads = 0;
    /** @var list<?string> */
    public array $validators = [];
    /** @var array<int, string> */
    public array $editors = [];
    /** @var array<int, array<string, ?string>> by default every label was applied by the author */
    public array $labelAppliers = [];
    /** @var array<int, ListedIssue> */
    private array $listed = [];
    private ?IssueListing $next = null;
    private bool $failing = false;

    /**
     * @param list<ListedIssue> $issues
     */
    public function lists(array $issues, ?string $validator = null): void
    {
        $this->next = IssueListing::listed($issues, $validator);
        $this->failing = false;
        foreach ($issues as $issue) {
            $this->listed[$issue->number] = $issue;
        }
    }

    public function listsUnchanged(): void
    {
        $this->next = IssueListing::unchanged();
        $this->failing = false;
    }

    public function fails(): void
    {
        $this->failing = true;
    }

    public function listOpen(string $project, ?string $validator): IssueListing
    {
        ++$this->listings;
        $this->validators[] = $validator;

        return $this->failing || !$this->next instanceof IssueListing ? throw new ForgeReadFailed('GitHub answered 502 to a read.') : $this->next;
    }

    public function historyOf(string $project, int $number): IssueHistory
    {
        ++$this->historyReads;
        $listed = $this->listed[$number] ?? null;
        $byAuthor = null === $listed ? [] : array_fill_keys($listed->labels, $listed->author);

        return new IssueHistory($this->editors[$number] ?? $listed->author ?? 'ana', $this->labelAppliers[$number] ?? $byAuthor);
    }
}
