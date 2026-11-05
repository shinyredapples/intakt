<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ProfileScanPath
{
    private const string DIRECTORY = 'config/php';

    /**
     * The scan path that also loads the check ini, or null when it already does.
     */
    public static function missingFrom(string $root, string|false $scanPath): ?string
    {
        $profile = $root.'/'.self::DIRECTORY;
        $directories = false === $scanPath ? [] : explode(PATH_SEPARATOR, $scanPath);

        foreach ($directories as $directory) {
            if (rtrim($directory, '/') === $profile) {
                return null;
            }
        }

        // Last, so the profile wins over every ini file the host scans before it.
        return (false === $scanPath ? '' : $scanPath).PATH_SEPARATOR.$profile;
    }
}
