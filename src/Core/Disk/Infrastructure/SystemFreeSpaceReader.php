<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Override;
use Symfony\Component\Process\Process;

// PHP reads free bytes itself but has no call for free inodes, so those come from df.
final readonly class SystemFreeSpaceReader implements FreeSpaceReader
{
    // Long enough for a slow network mount, short enough that a hung one does not stall the step that asked.
    private const int TIMEOUT_SECONDS = 10;

    #[Override]
    public function freeBytes(string $folder): int
    {
        $free = is_dir($folder) ? @disk_free_space($folder) : false;
        if (false === $free) {
            throw new FreeSpaceUnreadable($folder, 'the folder does not exist or its file system cannot be read');
        }

        return (int) $free;
    }

    // A timeout is not caught, so the caller sees a hung mount as the process timeout it is.
    #[Override]
    public function freeInodes(string $folder): ?int
    {
        $df = new Process(['df', '--output=itotal,iavail', '--', $folder], null, ['LC_ALL' => 'C'], null, self::TIMEOUT_SECONDS);
        $df->run();
        if (!$df->isSuccessful()) {
            throw new FreeSpaceUnreadable($folder, sprintf('df exited with %d: %s', $df->getExitCode() ?? -1, trim($df->getErrorOutput())));
        }

        return self::freeInodesOf($df->getOutput(), $folder);
    }

    // A file system such as btrfs reports a total of 0 because it allocates inodes as it goes.
    public static function freeInodesOf(string $output, string $folder): ?int
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        if (2 !== count($lines) || 1 !== preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $lines[1], $counts)) {
            throw new FreeSpaceUnreadable($folder, 'df printed no inode counts');
        }

        return '0' === $counts[1] ? null : (int) $counts[2];
    }
}
