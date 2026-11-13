<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

enum HeadAdmission: string
{
    case Pending = 'pending';
    case Red = 'red';
    case Green = 'green';
    // Kept apart from Red, because the ledger line and the owner must tell a head that does not boot from a failed CI run.
    case FailedBoot = 'failed_boot';
}
