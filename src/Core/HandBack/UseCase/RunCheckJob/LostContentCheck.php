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
        $named = $this->withEarlierPaths($namedByThePlan, $this->movesOnTheBranch($repository, $base, $head));
        $lost = [];

        foreach ($this->changedFiles($repository, $base, $head) as $file) {
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
        $raw = $this->git->exactOutput($repository, ['diff', '--raw', '-z', '-M', '--no-abbrev', $base, $head, '--']);

        return $this->parsed(explode("\0", $raw));
    }

    /**
     * @return list<ChangedFile>
     *
     * @throws HandBackNotChecked
     */
    private function movesOnTheBranch(string $repository, string $base, string $head): array
    {
        // Commit by commit, because a file moved and then rewritten below git's similarity threshold reads as removed in the diff of the whole branch.
        $raw = $this->git->exactOutput($repository, ['log', '--raw', '-z', '-M', '--no-abbrev', '--format=', $base.'..'.$head, '--']);
        $fields = array_values(array_filter(explode("\0", $raw), static fn (string $field): bool => '' !== $field));

        return array_values(array_filter($this->parsed($fields), static fn (ChangedFile $file): bool => $file->isRenamed()));
    }

    /**
     * @param list<string>      $named
     * @param list<ChangedFile> $moves
     *
     * @return list<string>
     */
    private function withEarlierPaths(array $named, array $moves): array
    {
        // A path the plan names covers every path the file had earlier on the branch, so a move before the rewrite keeps the exemption.
        do {
            $before = count($named);

            foreach ($moves as $move) {
                if (in_array($move->paths[1] ?? null, $named, true) && !in_array($move->paths[0], $named, true)) {
                    $named[] = $move->paths[0];
                }
            }
        } while (count($named) !== $before);

        return $named;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<ChangedFile>
     */
    private function parsed(array $fields): array
    {
        $files = [];
        $index = 0;

        while (str_starts_with($fields[$index] ?? '', ':')) {
            [$oldMode, $newMode, $oldBlob, $newBlob, $status] = explode(' ', substr($fields[$index], 1), 5) + ['', '', '', '', ''];
            // Two paths for a rename or a copy, because git names the old path and then the new one.
            $count = str_starts_with($status, 'R') || str_starts_with($status, 'C') ? 2 : 1;
            $files[] = new ChangedFile($status, $oldMode, $newMode, $oldBlob, $newBlob, array_slice($fields, $index + 1, $count));
            $index += $count + 1;
        }

        return $files;
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
