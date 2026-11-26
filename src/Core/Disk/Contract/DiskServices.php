<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class DiskServices
{
    public const string MIN_FREE_BYTES = 'intakt.disk.min_free_bytes';

    public const string MIN_FREE_INODES = 'intakt.disk.min_free_inodes';
}
