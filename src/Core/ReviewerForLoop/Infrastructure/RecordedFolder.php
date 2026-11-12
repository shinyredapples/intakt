<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Infrastructure;

final readonly class RecordedFolder
{
    /**
     * @return list<string>|null
     */
    // Listed literally rather than with glob(), because a folder name holding [, * or ? is a pattern to glob() and matches nothing.
    public static function jsonFiles(string $folder): ?array
    {
        $names = is_dir($folder) ? scandir($folder) : false;
        if (false === $names) {
            return null;
        }

        $paths = array_map(static fn (string $name): string => rtrim($folder, '/').'/'.$name, $names);

        return array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '.json') && is_file($path)));
    }
}
