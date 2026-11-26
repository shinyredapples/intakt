<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// An interface so tests set the readings, because a real disk cannot be filled to a floor on demand.
interface FreeSpaceReader
{
    /**
     * @throws FreeSpaceUnreadable
     */
    public function freeBytes(string $folder): int;

    // Null when the file system keeps no inode count, because then it cannot run out of inodes.
    /**
     * @throws FreeSpaceUnreadable
     * @throws ProcessTimedOutException
     */
    public function freeInodes(string $folder): ?int;
}
