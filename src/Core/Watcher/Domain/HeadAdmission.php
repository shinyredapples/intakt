<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

enum HeadAdmission: string
{
    case Pending = 'pending';
    case Red = 'red';
    case Green = 'green';
}
