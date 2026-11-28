<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

// Carries the reading beside the floor, so a page can say how far below it the disk is.
final readonly class DiskHold
{
    public function __construct(
        public DiskFloor $floor,
        public string $folder,
        public int $free,
        public int $minimum,
    ) {
    }

    public function reason(): string
    {
        return sprintf('the disk of %s holds new work: %d free %s, below the floor of %d', $this->folder, $this->free, $this->floor->value, $this->minimum);
    }
}
