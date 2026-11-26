<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class NewWorkHeld extends RuntimeException implements ReportableFailure
{
    public function __construct(
        public readonly DiskHeld $held,
    ) {
        parent::__construct(sprintf(
            'new work is held: %d free %s in %s, below the floor of %d; it can start once space is back',
            $held->free,
            $held->floor->value,
            $held->folder,
            $held->floorValue,
        ));
    }
}
