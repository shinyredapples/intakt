<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Override;

// For tests about something else, so a nearly full disk on the test machine never holds their work.
final readonly class DiskWithRoom implements DiskHeadroom
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
