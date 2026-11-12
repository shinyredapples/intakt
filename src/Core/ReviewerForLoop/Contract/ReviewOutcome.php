<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

enum ReviewOutcome: string
{
    case Approve = 'approve';
    case Changes = 'changes';
    case Unreadable = 'unreadable';
}
