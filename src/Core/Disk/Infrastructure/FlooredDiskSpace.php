<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Contract\DiskSpace;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FlooredDiskSpace implements DiskSpace
{
    public function __construct(
        private FreeSpaceReader $reader,
        #[Autowire(param: DiskServices::MIN_FREE_BYTES)]
        private int $minFreeBytes,
        #[Autowire(param: DiskServices::MIN_FREE_INODES)]
        private int $minFreeInodes,
    ) {
    }

    // Bytes first, so a disk below both floors names bytes and the inode count is not read at all.
    #[Override]
    public function holdAt(string $folder): ?DiskHold
    {
        return $this->bytesHold($folder) ?? $this->inodesHold($folder);
    }

    private function bytesHold(string $folder): ?DiskHold
    {
        $free = $this->reader->freeBytes($folder);

        return $free < $this->minFreeBytes ? new DiskHold(DiskFloor::Bytes, $folder, $free, $this->minFreeBytes) : null;
    }

    private function inodesHold(string $folder): ?DiskHold
    {
        $free = $this->reader->freeInodes($folder);

        return null !== $free && $free < $this->minFreeInodes ? new DiskHold(DiskFloor::Inodes, $folder, $free, $this->minFreeInodes) : null;
    }
}
