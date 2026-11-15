<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class AddedLines
{
    private const string FILE_HEADER = '/^diff --git /m';
    private const string HUNK_HEADER = '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/m';

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
        preg_match_all(self::HUNK_HEADER, $section, $hunks, PREG_SET_ORDER);
        $numbers = [];

        foreach ($hunks as $hunk) {
            $count = '' === ($hunk[2] ?? '') ? 1 : (int) $hunk[2];

            // Because a hunk that only removes lines adds none, and range() would count down.
            if (0 < $count) {
                array_push($numbers, ...range((int) $hunk[1], (int) $hunk[1] + $count - 1));
            }
        }

        return $numbers;
    }
}
