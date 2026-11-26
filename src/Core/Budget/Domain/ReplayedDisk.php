<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Override;

// Never holds, because a replayed day is judged on its recorded readings, not on the free space of the machine that replays it.
final readonly class ReplayedDisk implements DiskHeadroom
{
    #[Override]
    public function held(): ?DiskHeld
    {
        return null;
    }

    #[Override]
    public function admitWorkAt(string $destination): void
    {
    }
}
