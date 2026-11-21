<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Gives each line a diff adds with its file and its number in the changed file, because a dropped quote's nearest lines are shown at their place, and with the new side of its hunk (its context and added lines) and its index among them, because a credential of several lines is redacted only when its lines are seen together; a line starting with "diff --git " or "@@" is always a header, since every hunk line starts with a space, "+", "-" or "\".
final readonly class DiffAddedLines
{
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@~';

    public function __construct(private DiffPaths $paths)
    {
    }

    /**
     * @return list<array{string, int, string, string, int}>
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
     * @return list<array{string, int, string, string, int}>
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
     * @return list<array{string, int, string, string, int}>
     */
    private function addedInHunk(string $path, string $hunk): array
    {
        // Without the line break that ends the hunk, because the empty text after it is no line of the file.
        $lines = array_map(static fn (string $line): string => rtrim($line, "\r"), explode("\n", str_ends_with($hunk, "\n") ? substr($hunk, 0, -1) : $hunk));
        if (1 !== preg_match(self::HUNK, array_shift($lines), $header)) {
            return [];
        }

        $first = (int) $header[1];
        $newSide = [];
        $added = [];

        foreach ($lines as $line) {
            // A removed line and a "\ No newline" note are left out, because neither holds a line of the changed file; an empty line counts, because it is a blank context line whose space a tool trimmed.
            if ('' !== $line && !str_starts_with($line, '+') && !str_starts_with($line, ' ')) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $added[] = count($newSide);
            }

            $newSide[] = substr($line, 1);
        }

        $text = implode("\n", $newSide);

        return array_map(static fn (int $index): array => [$path, $first + $index, $newSide[$index], $text, $index], $added);
    }
}
