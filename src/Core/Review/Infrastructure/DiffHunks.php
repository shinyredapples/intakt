<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Gives each hunk as its file and the first and last line it covers in the changed file, because a finding names a line of that file; a line starting with "diff --git " or "@@" is always a header, since every hunk line starts with a space, "+", "-" or "\".
final readonly class DiffHunks
{
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@~m';

    public function __construct(private DiffPaths $paths)
    {
    }

    /**
     * @return list<array{string, int, int}>
     */
    public function in(string $diff): array
    {
        $files = preg_split('~^(?=diff --git )~m', $diff);
        $hunks = [];

        foreach (false === $files ? [] : $files as $file) {
            $hunks = [...$hunks, ...$this->hunksOf($file)];
        }

        return $hunks;
    }

    /**
     * @return list<array{string, int, int}>
     */
    private function hunksOf(string $file): array
    {
        $paths = $this->paths->in($file);
        if ([] === $paths) {
            return [];
        }

        // The last path is the changed file's own, because a rename or a copy names its source first.
        $path = array_last($paths);
        preg_match_all(self::HUNK, $file, $found, PREG_SET_ORDER);
        $ranges = array_map(static fn (array $hunk): array => [(int) $hunk[1], '' === ($hunk[2] ?? '') ? 1 : (int) $hunk[2]], $found);
        // Left out, because a hunk that only removes lines covers no line a finding can name.
        $covering = array_filter($ranges, static fn (array $range): bool => $range[1] > 0);

        return array_values(array_map(static fn (array $range): array => [$path, $range[0], $range[0] + $range[1] - 1], $covering));
    }
}
