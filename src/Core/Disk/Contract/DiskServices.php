<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class DiskServices
{
    public const string WATCHED_DIRECTORY = 'intakt.disk.watched_directory';
    public const string FREE_BYTES_FLOOR = 'intakt.disk.free_bytes_floor';
    public const string FREE_INODES_FLOOR = 'intakt.disk.free_inodes_floor';
}
