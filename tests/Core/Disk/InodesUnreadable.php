<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\FreeSpaceReader;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Override;

// Bytes to spare, so the floor check gets as far as the inode read that fails.
final readonly class InodesUnreadable implements FreeSpaceReader
{
    #[Override]
    public function freeBytes(string $folder): int
    {
        return PHP_INT_MAX;
    }

    #[Override]
    public function freeInodes(string $folder): int
    {
        throw FreeSpaceUnreadable::of($folder, 'inodes');
    }
}
