<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum BranchChangeState: string
{
    case Open = 'open';
    case Merged = 'merged';
    // Kept apart from Open, because cleanup waits 30 days for a closed one and never deletes an open one.
    case ClosedUnmerged = 'closed-unmerged';
}
