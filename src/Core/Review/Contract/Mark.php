<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum Mark: string
{
    case Act = 'act';
    case Defer = 'defer';
    case Reject = 'reject';
}
