<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum CiRunState: string
{
    case Unfinished = 'unfinished';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
