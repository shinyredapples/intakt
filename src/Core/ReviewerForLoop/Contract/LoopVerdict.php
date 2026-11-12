<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

enum LoopVerdict: string
{
    case Approve = 'approve';
    case Changes = 'changes';
    case Unreadable = 'unreadable';
}
