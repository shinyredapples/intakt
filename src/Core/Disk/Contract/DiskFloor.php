<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

enum DiskFloor: string
{
    case Bytes = 'bytes';
    case Inodes = 'inodes';
}
