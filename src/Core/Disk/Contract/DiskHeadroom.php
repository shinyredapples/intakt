<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use NoDiscard;

// Never stored: every ask reads the disk again, so a hold clears on its own once space is back.
interface DiskHeadroom
{
    #[NoDiscard]
    public function held(): ?DiskHeld;
}
