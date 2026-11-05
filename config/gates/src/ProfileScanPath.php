<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ProfileScanPath
{
    private const string DIRECTORY = 'config/php';

    public static function missingFrom(string $root, ?string $scanPath): ?string
    {
        $profile = $root.'/'.self::DIRECTORY;
        $directories = null === $scanPath ? [] : explode(PATH_SEPARATOR, $scanPath);

        foreach ($directories as $directory) {
            if (rtrim($directory, '/') === $profile) {
                return null;
            }
        }

        // Last, so the profile wins over every ini file the host scans before it.
        return ($scanPath ?? '').PATH_SEPARATOR.$profile;
    }
}
