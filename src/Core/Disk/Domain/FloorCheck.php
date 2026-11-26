<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Domain;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\FreeSpaceReader;
use Override;

final readonly class FloorCheck implements DiskHeadroom
{
    public function __construct(
        private FreeSpaceReader $reader,
        private string $folder,
        private int $bytesFloor,
        private int $inodesFloor,
    ) {
        if ($bytesFloor < 1) {
            throw FloorRefused::belowOne('free bytes', $bytesFloor);
        }

        if ($inodesFloor < 1) {
            throw FloorRefused::belowOne('free inodes', $inodesFloor);
        }
    }

    #[Override]
    public function held(): ?DiskHeld
    {
        $bytes = $this->reader->freeBytes($this->folder);
        if ($bytes < $this->bytesFloor) {
            return new DiskHeld(DiskFloor::Bytes, $this->folder, $bytes, $this->bytesFloor);
        }

        $inodes = $this->reader->freeInodes($this->folder);

        return $inodes < $this->inodesFloor ? new DiskHeld(DiskFloor::Inodes, $this->folder, $inodes, $this->inodesFloor) : null;
    }
}
