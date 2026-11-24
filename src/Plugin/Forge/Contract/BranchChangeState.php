<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum BranchChangeState: string
{
    case Open = 'open';
    case Merged = 'merged';
    case ClosedUnmerged = 'closed_unmerged';
}
