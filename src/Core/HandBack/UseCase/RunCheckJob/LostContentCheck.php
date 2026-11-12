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
        $lost = [];

        foreach ($this->changedFiles($repository, $head) as $file) {
            $content = $this->unnamedLoss($repository, $file, $namedByThePlan);

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
    private function changedFiles(string $repository, string $head): array
    {
        // Against the merge base, because a file main shrank after the branch started is not this change's loss; renames are followed, so a moved file is not read as removed.
        $main = $this->git->output($repository, ['symbolic-ref', '--quiet', 'HEAD']);
        $base = $this->git->output($repository, ['merge-base', $main, $head]);
        $raw = $this->git->exactOutput($repository, ['diff', '--raw', '-z', '-M', '--no-abbrev', $base, $head, '--']);

        return $this->parsed(explode("\0", $raw));
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
        $after = $file->isRemoved() ? '' : $this->git->exactOutput($repository, ['cat-file', 'blob', $file->newBlob]);

        return LostContent::in($file->path(), $before, $after);
    }
}
