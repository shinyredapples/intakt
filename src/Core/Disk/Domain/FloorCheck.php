<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Domain;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Contract\FreeSpaceReader;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Override;

final readonly class FloorCheck implements DiskHeadroom
{
    public function __construct(
        private FreeSpaceReader $reader,
        private string $folder,
        private int $bytesFloor,
        private int $inodesFloor,
    ) {
        FloorRefused::unlessAtLeastOne(DiskServices::FREE_BYTES_FLOOR, $bytesFloor);
        FloorRefused::unlessAtLeastOne(DiskServices::FREE_INODES_FLOOR, $inodesFloor);
    }

    #[Override]
    public function held(): ?DiskHeld
    {
        return $this->heldIn($this->folder);
    }

    #[Override]
    public function admitWorkAt(string $destination): void
    {
        $held = $this->heldIn($this->nearestExisting($destination));

        if ($held instanceof DiskHeld) {
            throw new NewWorkHeld($held);
        }
    }

    private function heldIn(string $folder): ?DiskHeld
    {
        $bytes = $this->reader->freeBytes($folder);
        if ($bytes < $this->bytesFloor) {
            return new DiskHeld(DiskFloor::Bytes, $folder, $bytes, $this->bytesFloor);
        }

        $inodes = $this->reader->freeInodes($folder);

        return $inodes < $this->inodesFloor ? new DiskHeld(DiskFloor::Inodes, $folder, $inodes, $this->inodesFloor) : null;
    }

    // The checkout's own folder is made later, so its file system is the one of the deepest parent that exists now.
    private function nearestExisting(string $destination): string
    {
        $folder = $destination;
        while (!is_dir($folder) && dirname($folder) !== $folder) {
            $folder = dirname($folder);
        }

        return $folder;
    }
}
