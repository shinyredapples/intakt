<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Override;

// A replayed day is judged on its recorded readings only, never on the free space of the machine that replays it.
final readonly class ReplayedDisk implements DiskHeadroom
{
    #[Override]
    public function held(): ?DiskHeld
    {
        return null;
    }
}
