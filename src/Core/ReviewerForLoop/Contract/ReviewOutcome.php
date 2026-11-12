<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

enum ReviewOutcome: string
{
    case Approve = 'approve';
    case Changes = 'changes';
    case Unreadable = 'unreadable';
}
