<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use RuntimeException;

// Thrown as the cause of a step's own failure, so a caller further up tells a disk hold, which clears by itself, from a failure by its type and never by its words.
final class NewWorkHeld extends RuntimeException
{
    public function __construct(public readonly DiskHold $hold)
    {
        parent::__construct($hold->reason());
    }
}
