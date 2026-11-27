<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\SummarySection;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileStatus;

// One description for the review and the identity check, because a verdict counts only under the panel it was given, and both must choose the same one.
final readonly class LocalChangeDescription
{
    public function __construct(
        private SummarySection $summary = new SummarySection(),
        private DocsPages $docs = new DocsPages(),
    ) {
    }

    public function of(LocalGitForge $forge, Change $change, Labels $labels): ChangeDescription
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);

        return new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), new Paths(...$paths), $labels, new Paths(...$this->touchedSummaries($forge, $change)));
    }

    // Read from both sides of the diff, because a summary can change outside the lines a hunk shows, and a deleted page loses its summary as an added one gains it.
    /**
     * @return list<string>
     */
    private function touchedSummaries(LocalGitForge $forge, Change $change): array
    {
        $touched = [];

        foreach ($change->diff->files as $file) {
            if ($this->docs->contains($file->path) && $this->summary->changedBetween($this->before($forge, $change, $file), $this->after($forge, $change, $file))) {
                $touched[] = $file->path;
            }
        }

        return $touched;
    }

    private function before(LocalGitForge $forge, Change $change, ChangedFile $file): string
    {
        return FileStatus::Added === $file->status ? '' : $this->pageAt($forge, $change->commits->base, $file->path);
    }

    private function after(LocalGitForge $forge, Change $change, ChangedFile $file): string
    {
        return FileStatus::Deleted === $file->status ? '' : $this->pageAt($forge, $change->commits->head, $file->path);
    }

    // A side that holds no regular file holds no page, so a gitlink named like a page neither aborts the read nor touches a summary.
    private function pageAt(LocalGitForge $forge, CommitId $commit, string $path): string
    {
        return $forge->holdsRegularFileAt($commit, $path) ? $forge->fileAt($commit, $path)->content : '';
    }
}
