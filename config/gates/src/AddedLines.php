<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class AddedLines
{
    private const string FILE_HEADER = '/^diff --git /m';
    private const string HUNK_HEADER = '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/';

    /**
     * @param list<string> $paths
     *
     * @return list<array{string, list<int>}>
     *
     * @throws ScopeFailure
     */
    public function byPath(array $paths, string $patch): array
    {
        $sections = array_slice((array) preg_split(self::FILE_HEADER, $patch), 1);

        // Because the patch names its files only in quoted headers, each section is paired with the plain path git listed for it in the same order.
        if (count($sections) !== count($paths)) {
            throw new ScopeFailure('git diff listed '.count($paths).' files but printed '.count($sections).' patches');
        }

        return array_map(fn (string $path, string $section): array => [$path, $this->numbersIn($section)], $paths, array_map(strval(...), $sections));
    }

    /**
     * @return list<int>
     */
    private function numbersIn(string $section): array
    {
        $numbers = [];
        $next = null;

        // Because git's diff.interHunkContext can join nearby hunks with unchanged lines, only the lines the patch marks with a plus are added.
        foreach (explode("\n", $section) as $line) {
            if (1 === preg_match(self::HUNK_HEADER, $line, $hunk)) {
                $next = (int) $hunk[1];
            } elseif (null !== $next && str_starts_with($line, '+')) {
                $numbers[] = $next++;
            } elseif (null !== $next && str_starts_with($line, ' ')) {
                ++$next;
            }
        }

        return $numbers;
    }
}
