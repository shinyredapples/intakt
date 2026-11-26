<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use NoDiscard;

// Never stored: every ask reads the disk again, so a hold clears on its own once space is back.
interface DiskHeadroom
{
    #[NoDiscard]
    public function held(): ?DiskHeld;

    // The nearest folder of the destination that exists is read, because a checkout may land on another file system than the watched folder.
    /**
     * @throws NewWorkHeld
     */
    public function admitWorkAt(string $destination): void;
}
