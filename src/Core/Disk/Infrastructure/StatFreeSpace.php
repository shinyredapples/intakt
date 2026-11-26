<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\FreeSpaceReader;
use Override;
use Symfony\Component\Process\Process;

final readonly class StatFreeSpace implements FreeSpaceReader
{
    private const float STAT_SECONDS = 10.0;

    #[Override]
    public function freeBytes(string $folder): int
    {
        $free = is_dir($folder) ? disk_free_space($folder) : false;
        if (false === $free) {
            throw FreeSpaceUnreadable::of($folder, 'bytes');
        }

        return (int) $free;
    }

    // PHP has no call for inodes, so the file system's own tool reports them.
    #[Override]
    public function freeInodes(string $folder): int
    {
        $stat = new Process(['stat', '--file-system', '--format=%d', $folder], null, null, null, self::STAT_SECONDS);
        $stat->run();
        $output = trim($stat->getOutput());
        if (!$stat->isSuccessful() || 1 !== preg_match('/^\d+$/', $output)) {
            throw FreeSpaceUnreadable::of($folder, 'inodes');
        }

        return (int) $output;
    }
}
