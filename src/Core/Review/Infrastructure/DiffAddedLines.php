<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Gives each line a diff adds as its file and its number in the changed file, because a dropped quote's nearest lines are shown at their place and their text is read from the checked-out file; a line starting with "diff --git " or "@@" is always a header, since every hunk line starts with a space, "+", "-" or "\".
final readonly class DiffAddedLines
{
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@~';

    public function __construct(private DiffPaths $paths)
    {
    }

    /**
     * @return list<array{string, int}>
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
     * @return list<array{string, int}>
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
     * @return list<array{string, int}>
     */
    private function addedInHunk(string $path, string $hunk): array
    {
        // Without the line break that ends the hunk, because the empty text after it is no line of the file.
        $lines = explode("\n", str_ends_with($hunk, "\n") ? substr($hunk, 0, -1) : $hunk);
        if (1 !== preg_match(self::HUNK, array_shift($lines), $header)) {
            return [];
        }

        $number = (int) $header[1];
        $added = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '+')) {
                $added[] = [$path, $number];
            }

            // A removed line and a "\ No newline" note hold no line of the changed file; an empty line counts, because it is a blank context line whose space a tool trimmed, with or without its carriage return.
            if (str_starts_with($line, '+') || str_starts_with($line, ' ') || '' === $line || "\r" === $line) {
                ++$number;
            }
        }

        return $added;
    }
}
