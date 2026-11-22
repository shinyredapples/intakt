<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// Answers the next scripted listing and counts every read, because a test must see which reads a tick made.
final class RecordedIssueReads implements IssueReads
{
    public int $listings = 0;
    /** @var list<?string> */
    public array $validators = [];
    // Run once on the next listing, so a test can force what another worker does in the middle of a tick.
    public ?Closure $during = null;
    // Each item as it was last scripted, so a scripted history can answer the text and labels GitHub holds.
    /** @var array<int, ListedIssue> */
    public array $lastListed = [];
    private ?IssueListing $next = null;

    /**
     * @param list<ListedIssue> $issues
     */
    public function lists(array $issues, ?string $validator = 'W/"issues-1"'): void
    {
        $this->next = IssueListing::listed($issues, $validator);
        foreach ($issues as $issue) {
            $this->lastListed[$issue->number] = $issue;
        }
    }

    public function listsUnchanged(): void
    {
        $this->next = IssueListing::unchanged();
    }

    public function listOpen(string $project, ?string $validator): IssueListing
    {
        ++$this->listings;
        $this->validators[] = $validator;
        $during = $this->during;
        $this->during = null;
        if ($during instanceof Closure) {
            $during();
        }

        return $this->next ?? throw new ForgeReadFailed('GitHub answered 502 to a read.');
    }

    // The issue as it was last scripted, because GitHub answers a single read with what a listing would show.
    public function readOpen(string $project, int $number): ?ListedIssue
    {
        $issue = $this->lastListed[$number] ?? null;

        return null === $issue || $issue->isPullRequest ? null : $issue;
    }
}
