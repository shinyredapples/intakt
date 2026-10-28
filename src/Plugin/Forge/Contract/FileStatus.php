<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum FileStatus: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
}
