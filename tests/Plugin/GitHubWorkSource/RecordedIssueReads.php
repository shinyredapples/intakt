<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// Answers the next scripted listing and counts every read, because a test must see which reads a tick made.
final class RecordedIssueReads implements IssueReads
{
    public int $listings = 0;
    public int $editorReads = 0;
    /** @var list<?string> */
    public array $validators = [];
    /** @var array<int, string> */
    public array $editors = [];
    private ?IssueListing $next = null;
    private bool $failing = false;

    /**
     * @param list<ListedIssue> $issues
     */
    public function lists(array $issues, ?string $validator = null): void
    {
        $this->next = IssueListing::listed($issues, $validator);
        $this->failing = false;
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

        return $this->failing || null === $this->next ? throw new ForgeReadFailed('GitHub answered 502 to a read.') : $this->next;
    }

    public function lastEditorOf(string $project, int $number): string
    {
        ++$this->editorReads;

        return $this->editors[$number] ?? 'ana';
    }
}
