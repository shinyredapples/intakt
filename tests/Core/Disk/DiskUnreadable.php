<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Override;

final readonly class DiskUnreadable implements DiskHeadroom
{
    #[Override]
    public function held(): ?DiskHeld
    {
        throw FreeSpaceUnreadable::of('var', 'inodes');
    }

    #[Override]
    public function admitWorkAt(string $destination): void
    {
        throw FreeSpaceUnreadable::of($destination, 'inodes');
    }
}
