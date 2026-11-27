<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use NoDiscard;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// Measured on every call and never kept, so a hold clears by itself once space is back and work already running is never cut.
interface DiskSpace
{
    /**
     * @throws FreeSpaceUnreadable
     * @throws ProcessTimedOutException
     */
    #[NoDiscard]
    public function holdAt(string $folder): ?DiskHold;
}
