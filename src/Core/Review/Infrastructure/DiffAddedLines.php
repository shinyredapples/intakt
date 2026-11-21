<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Gives each line a diff adds with its file and its number in the changed file, because a dropped quote's nearest lines are shown at their place; a line starting with "diff --git " or "@@" is always a header, since every hunk line starts with a space, "+", "-" or "\".
final readonly class DiffAddedLines
{
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@~';

    public function __construct(private DiffPaths $paths)
    {
    }

    /**
     * @return list<array{string, int, string}>
     */
    public function in(string $diff): array
    {
        $files = preg_split('~^(?=diff --git )~m', $diff);
        $added = [];

        foreach (false === $files ? [] : $files as $file) {
            $added = [...$added, ...$this->addedIn($file)];
        }

        return $added;
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function addedIn(string $file): array
    {
        $paths = $this->paths->in($file);
        $hunks = preg_split('~^(?=@@)~m', $file);
        if ([] === $paths || false === $hunks) {
            return [];
        }

        $added = [];
        // The first part is the file header, because its "+++" line is no added line; the last path is the changed file's own, because a rename or a copy names its source first.
        foreach (array_slice($hunks, 1) as $hunk) {
            $added = [...$added, ...$this->addedInHunk(array_last($paths), $hunk)];
        }

        return $added;
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function addedInHunk(string $path, string $hunk): array
    {
        $lines = array_map(static fn (string $line): string => rtrim($line, "\r"), explode("\n", $hunk));
        if (1 !== preg_match(self::HUNK, array_shift($lines), $header)) {
            return [];
        }

        $next = (int) $header[1];
        $added = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '+')) {
                $added[] = [$path, $next, substr($line, 1)];
            }

            // Not counted for a removed line or a "\ No newline" note, because neither holds a line of the changed file; an empty line counts, because it is a blank context line whose space a tool trimmed.
            if ('' === $line || str_starts_with($line, '+') || str_starts_with($line, ' ')) {
                ++$next;
            }
        }

        return $added;
    }
}
