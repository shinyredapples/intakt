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
        if ([] === $paths) {
            return [];
        }

        // The last path is the changed file's own, because a rename or a copy names its source first.
        $path = array_last($paths);
        $added = [];
        $next = null;

        foreach (explode("\n", $file) as $line) {
            $line = rtrim($line, "\r");

            if (1 === preg_match(self::HUNK, $line, $hunk)) {
                $next = (int) $hunk[1];

                continue;
            }

            // Before the first hunk, because the file header's "+++" line is no added line.
            if (null === $next) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $added[] = [$path, $next, substr($line, 1)];
            }

            // A removed line and a "\ No newline" note hold no line of the changed file; an empty line is a blank context line whose space a tool trimmed.
            if ('' === $line || str_starts_with($line, '+') || str_starts_with($line, ' ')) {
                ++$next;
            }
        }

        return $added;
    }
}
