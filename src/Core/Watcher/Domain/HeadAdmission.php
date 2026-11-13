<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

enum HeadAdmission: string
{
    case Pending = 'pending';
    case Red = 'red';
    case Green = 'green';
    // Green on CI but failing to boot while main boots; goes back like red.
    case FailedBoot = 'failed_boot';
}
