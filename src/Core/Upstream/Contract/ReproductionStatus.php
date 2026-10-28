<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\Contract;

enum ReproductionStatus: string
{
    case Passes = 'passes';
    case StillFails = 'still fails';
}
