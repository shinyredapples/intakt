<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum Decision: string
{
    case Approve = 'approve';
    case Changes = 'changes';
    case Blocked = 'blocked';
}
