<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

// What the Status page reads: which floor was crossed, where, and by how much.
final readonly class DiskHeld
{
    public function __construct(
        public DiskFloor $floor,
        public string $folder,
        public int $free,
        public int $floorValue,
    ) {
    }
}
