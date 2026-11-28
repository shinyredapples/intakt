<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

// A step measures where it will write before it makes anything, because on a disk out of inodes the mkdir itself fails; a folder that does not exist yet lies on the disk of the nearest folder above it that does.
final readonly class NearestFolder
{
    public static function of(string $path): string
    {
        while (!is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        return $path;
    }
}
