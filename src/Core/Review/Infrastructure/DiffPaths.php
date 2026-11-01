<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Reads the paths from the file headers of a git diff, because the round stores the diff only; a hunk line that looks like a header is never read as one.
final readonly class DiffPaths
{
    // A path git quoted (C-style, for unusual characters) or one without spaces.
    private const string PATH = '("(?:[^"\\\\]|\\\\.)*"|\S+)';

    // Each header and whether its paths carry git's a/ or b/ prefix; a header path with spaces is taken only when both sides name the same file, the one case its split is certain.
    private const array HEADERS = [
        '~^diff --git a/(.+) b/\1$~' => false,
        '~^diff --git '.self::PATH.' '.self::PATH.'$~' => true,
        '~^(?:---|\+\+\+) '.self::PATH.'$~' => true,
        '~^(?:rename|copy) (?:from|to) ("(?:[^"\\\\]|\\\\.)*"|.+)$~' => false,
    ];

    /**
     * @return list<string>
     */
    public function in(string $diff): array
    {
        $paths = [];

        foreach ($this->headerLinesOf($diff) as $line) {
            $paths = [...$paths, ...$this->pathsOn($line)];
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function headerLinesOf(string $diff): array
    {
        $headers = [];
        $inHunk = false;

        foreach (preg_split('~\r?\n~', $diff) ?: [] as $line) {
            $inHunk = !str_starts_with($line, 'diff --git ') && ($inHunk || str_starts_with($line, '@@'));

            if (!$inHunk) {
                $headers[] = $line;
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function pathsOn(string $line): array
    {
        foreach (self::HEADERS as $header => $prefixed) {
            if (1 === preg_match($header, $line, $found)) {
                return $this->filesIn(array_slice($found, 1), $prefixed);
            }
        }

        return [];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function filesIn(array $paths, bool $prefixed): array
    {
        $files = [];

        foreach (array_map($this->unquoted(...), $paths) as $path) {
            if ('/dev/null' !== $path) {
                $files[] = $prefixed ? substr($path, 2) : $path;
            }
        }

        return $files;
    }

    private function unquoted(string $path): string
    {
        return str_starts_with($path, '"') ? stripcslashes(substr($path, 1, -1)) : $path;
    }
}
