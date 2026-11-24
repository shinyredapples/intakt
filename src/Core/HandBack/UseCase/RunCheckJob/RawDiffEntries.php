<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

// The one reader of "git diff --raw -z" and "git log --raw -z" output, because the loss check and the regression test finder must read a diff alike.
final readonly class RawDiffEntries
{
    /**
     * @return list<ChangedFile>
     */
    public static function of(string $output): array
    {
        $fields = explode("\0", $output);
        $files = [];
        $index = 0;

        while (($file = self::entryAt($fields, $index)) instanceof ChangedFile) {
            $files[] = $file;
            $index += $file->fieldCount();
        }

        return $files;
    }

    /**
     * @param list<string> $fields
     */
    public static function entryAt(array $fields, int $index): ?ChangedFile
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
}
