<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// Reads the paths from the file headers of a git diff, because the round stores the diff only; a hunk line that looks like a header is never read as one.
final readonly class DiffPaths
{
    // Quoted or free of spaces, because git C-quotes a path with unusual characters and a space would end an unquoted one.
    private const string PATH = '("(?:[^"\\\\]|\\\\.)*"|\S+)';

    // Tried first, because a header path with spaces splits for certain only when both sides name the same file.
    private const array WITHOUT_PREFIX = [
        '~^diff --git a/(.+) b/\1$~',
        '~^(?:rename|copy) (?:from|to) ("(?:[^"\\\\]|\\\\.)*"|.+)$~',
    ];

    private const array WITH_PREFIX = [
        '~^diff --git '.self::PATH.' '.self::PATH.'$~',
        '~^(?:---|\+\+\+) '.self::PATH.'$~',
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

        foreach (explode("\n", $diff) as $line) {
            $line = rtrim($line, "\r");
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
        foreach (self::WITHOUT_PREFIX as $header) {
            if (1 === preg_match($header, $line, $found)) {
                return $this->filesIn(array_slice($found, 1));
            }
        }

        foreach (self::WITH_PREFIX as $header) {
            if (1 === preg_match($header, $line, $found)) {
                return array_map(static fn (string $path): string => substr($path, 2), $this->filesIn(array_slice($found, 1)));
            }
        }

        return [];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function filesIn(array $paths): array
    {
        $files = array_filter(array_map($this->unquoted(...), $paths), static fn (string $path): bool => '/dev/null' !== $path);

        return array_values($files);
    }

    private function unquoted(string $path): string
    {
        return str_starts_with($path, '"') ? stripcslashes(substr($path, 1, -1)) : $path;
    }
}
