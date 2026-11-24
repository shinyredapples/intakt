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
        $named = $this->withTheBasePathOfEachNewNamedFile($namedByThePlan, $changed, $this->movesOfEachCommit($repository, $base, $head));
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
        return RawDiffEntries::of($this->git->exactOutput($repository, ['diff', '--raw', '-z', '-M', '--no-abbrev', $base, $head, '--']));
    }

    /**
     * @return list<list<ChangedFile>>
     *
     * @throws HandBackNotChecked
     */
    private function movesOfEachCommit(string $repository, string $base, string $head): array
    {
        // Commit by commit, the head's first, because a file moved and then rewritten below git's similarity threshold reads as removed in the diff of the whole branch; with each commit's hash, because a path is followed back through one commit's moves at a time. Additions too, because a path created anew holds another file than the one moved there before. Along the branch's first parents with each merge diffed against its first parent, because "log" prints no entries for a merge otherwise, so a path created in a merge of main would not end the walk.
        $fields = explode("\0", $this->git->exactOutput($repository, ['log', '--raw', '-z', '-M', '--no-abbrev', '--first-parent', '--diff-merges=first-parent', '--format=%H', $base.'..'.$head, '--']));
        $commits = [];
        $moves = [];
        $index = 0;

        while ($index < count($fields)) {
            $file = RawDiffEntries::entryAt($fields, $index);

            // A field that is no entry starts a commit, because git prints the hash before the commit's entries.
            if (!$file instanceof ChangedFile) {
                $commits[] = $moves;
                $moves = [];
                ++$index;

                continue;
            }

            if ($file->isRenamed() || $file->isAdded()) {
                $moves[] = $file;
            }

            $index += $file->fieldCount();
        }

        $commits[] = $moves;

        return $commits;
    }

    /**
     * @param list<string>            $named
     * @param list<ChangedFile>       $changed
     * @param list<list<ChangedFile>> $movesOfEachCommit
     *
     * @return list<string>
     */
    private function withTheBasePathOfEachNewNamedFile(array $named, array $changed, array $movesOfEachCommit): array
    {
        // A named path that existed at the base names the file that was there, so a file moved onto it later is not covered; a path the branch created names the file there at the head, which is also covered under the one path it had at the base, so a move before the rewrite keeps the exemption.
        $basePaths = [];

        foreach ($named as $path) {
            if (array_any($changed, static fn (ChangedFile $file): bool => $file->isNewAt($path))) {
                $basePaths[] = $this->pathAtTheBase($path, $movesOfEachCommit);
            }
        }

        return array_values(array_unique([...$named, ...array_filter($basePaths, is_string(...))]));
    }

    /**
     * @param list<list<ChangedFile>> $movesOfEachCommit
     */
    private function pathAtTheBase(string $path, array $movesOfEachCommit): ?string
    {
        // From the head back to the base, replacing the path only in a commit that moved a file onto it, so the path follows one file and not every move on the branch; a commit that created the path ends the walk, because the file there was made on the branch and had no path at the base.
        foreach ($movesOfEachCommit as $moves) {
            $move = array_find($moves, static fn (ChangedFile $move): bool => $move->isCreatedAt($path) || $move->movedTo($path));

            if (true === $move?->isAdded()) {
                return null;
            }

            $path = $move?->oldPath() ?? $path;
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

        $sizeAfter = $file->isGone() ? 0 : (int) $this->git->output($repository, ['cat-file', '-s', $file->newBlob]);

        if (!LostContent::couldBeLost((int) $this->git->output($repository, ['cat-file', '-s', $file->oldBlob]), $sizeAfter)) {
            return null;
        }

        $before = $this->git->exactOutput($repository, ['cat-file', 'blob', $file->oldBlob]);
        $after = $file->isGone() ? '' : $this->git->exactOutput($repository, ['cat-file', 'blob', $file->newBlob]);

        return LostContent::in($file->path(), $before, $after);
    }
}
