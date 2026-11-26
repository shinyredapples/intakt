<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

// Typed, because the Status page reads which floor was crossed and where without parsing a message.
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
