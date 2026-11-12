<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Domain\LostContent;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;

final readonly class LostContentCheck
{
    public function __construct(
        private RepositoryGit $git,
    ) {
    }

    /**
     * @param list<string> $namedByThePlan
     *
     * @return list<LostContent>
     *
     * @throws HandBackNotChecked
     */
    public function of(string $repository, string $head, array $namedByThePlan): array
    {
        // Against the merge base, because a file main shrank after the branch started is not this change's loss; renames are followed, so a moved file is not read as removed.
        $main = $this->git->output($repository, ['symbolic-ref', '--quiet', 'HEAD']);
        $base = $this->git->output($repository, ['merge-base', $main, $head]);
        $changed = $this->changedFiles($repository, $base, $head);
        $named = $this->withTheBasePathOfEachNewNamedFile($namedByThePlan, $changed, $this->renamesOfEachCommit($repository, $base, $head));
        $lost = [];

        foreach ($changed as $file) {
            $content = $this->unnamedLoss($repository, $file, $named);

            if ($content instanceof LostContent) {
                $lost[] = $content;
            }
        }

        return $lost;
    }

    /**
     * @return list<ChangedFile>
     *
     * @throws HandBackNotChecked
     */
    private function changedFiles(string $repository, string $base, string $head): array
    {
        $fields = explode("\0", $this->git->exactOutput($repository, ['diff', '--raw', '-z', '-M', '--no-abbrev', $base, $head, '--']));
        $files = [];
        $index = 0;

        while (($file = $this->entryAt($fields, $index)) instanceof ChangedFile) {
            $files[] = $file;
            $index += $file->fieldCount();
        }

        return $files;
    }

    /**
     * @return list<list<ChangedFile>>
     *
     * @throws HandBackNotChecked
     */
    private function renamesOfEachCommit(string $repository, string $base, string $head): array
    {
        // Commit by commit, the head's first, because a file moved and then rewritten below git's similarity threshold reads as removed in the diff of the whole branch; with each commit's hash, because a path is followed back through one commit's moves at a time.
        $fields = explode("\0", $this->git->exactOutput($repository, ['log', '--raw', '-z', '-M', '--no-abbrev', '--topo-order', '--format=%H', $base.'..'.$head, '--']));
        $commits = [];
        $renames = [];
        $index = 0;

        while ($index < count($fields)) {
            $file = $this->entryAt($fields, $index);

            // A field that is no entry starts a commit, because git prints the hash before the commit's entries.
            if (!$file instanceof ChangedFile) {
                $commits[] = $renames;
                $renames = [];
                ++$index;

                continue;
            }

            if ($file->isRenamed()) {
                $renames[] = $file;
            }

            $index += $file->fieldCount();
        }

        $commits[] = $renames;

        return $commits;
    }

    /**
     * @param list<string> $fields
     */
    private function entryAt(array $fields, int $index): ?ChangedFile
    {
        // Trimmed, because "log -z" puts a line break before a commit's first entry.
        $field = ltrim($fields[$index] ?? '', "\n");

        if (!str_starts_with($field, ':')) {
            return null;
        }

        [$oldMode, $newMode, $oldBlob, $newBlob, $status] = explode(' ', substr($field, 1), 5) + ['', '', '', '', ''];
        // Two paths for a rename or a copy, because git names the old path and then the new one.
        $count = str_starts_with($status, 'R') || str_starts_with($status, 'C') ? 2 : 1;

        return new ChangedFile($status, $oldMode, $newMode, $oldBlob, $newBlob, array_slice($fields, $index + 1, $count));
    }

    /**
     * @param list<string>            $named
     * @param list<ChangedFile>       $changed
     * @param list<list<ChangedFile>> $renamesOfEachCommit
     *
     * @return list<string>
     */
    private function withTheBasePathOfEachNewNamedFile(array $named, array $changed, array $renamesOfEachCommit): array
    {
        // A named path that existed at the base names the file that was there, so a file moved onto it later is not covered; a path the branch created names the file there at the head, which is also covered under the one path it had at the base, so a move before the rewrite keeps the exemption.
        $basePaths = [];

        foreach ($named as $path) {
            if (array_any($changed, static fn (ChangedFile $file): bool => $file->isNewAt($path))) {
                $basePaths[] = $this->pathAtTheBase($path, $renamesOfEachCommit);
            }
        }

        return array_values(array_unique([...$named, ...$basePaths]));
    }

    /**
     * @param list<list<ChangedFile>> $renamesOfEachCommit
     */
    private function pathAtTheBase(string $path, array $renamesOfEachCommit): string
    {
        // From the head back to the base, replacing the path only in a commit that moved a file onto it, so the path follows one file and not every move on the branch.
        foreach ($renamesOfEachCommit as $renames) {
            foreach ($renames as $rename) {
                if ($rename->movedTo($path)) {
                    $path = $rename->oldPath();

                    break;
                }
            }
        }

        return $path;
    }

    /**
     * @param list<string> $namedByThePlan
     *
     * @throws HandBackNotChecked
     */
    private function unnamedLoss(string $repository, ChangedFile $file, array $namedByThePlan): ?LostContent
    {
        if (!$file->couldLoseContent() || $file->isNamedIn($namedByThePlan)) {
            return null;
        }

        $before = $this->git->exactOutput($repository, ['cat-file', 'blob', $file->oldBlob]);
        $after = $file->isGone() ? '' : $this->git->exactOutput($repository, ['cat-file', 'blob', $file->newBlob]);

        return LostContent::in($file->path(), $before, $after);
    }
}
