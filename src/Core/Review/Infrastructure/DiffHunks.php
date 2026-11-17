<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Reads the lines each hunk covers in the changed file, because a finding names a line of that file; a line starting with "diff --git " or "@@" is always a header, since every hunk line starts with a space, "+", "-" or "\".
final readonly class DiffHunks
{
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@~m';

    public function __construct(private DiffPaths $paths)
    {
    }

    /**
     * @return list<array{string, int, int}> the file, then the first and last line the hunk covers
     */
    public function in(string $diff): array
    {
        $hunks = [];

        foreach (preg_split('~^(?=diff --git )~m', $diff) ?: [] as $file) {
            $paths = $this->paths->in($file);
            // The last path is the changed file's own, because a rename or a copy names its source first.
            if ([] === $paths) {
                continue;
            }

            $path = $paths[array_key_last($paths)];
            preg_match_all(self::HUNK, $file, $found, PREG_SET_ORDER);
            foreach ($found as $hunk) {
                $first = (int) $hunk[1];
                $count = '' === ($hunk[2] ?? '') ? 1 : (int) $hunk[2];
                // A hunk that only removes lines covers no line of the changed file.
                if ($count > 0) {
                    $hunks[] = [$path, $first, $first + $count - 1];
                }
            }
        }

        return $hunks;
    }
}
