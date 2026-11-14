<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Defer and reject apply to a P3 only, because a P1 or P2 must always reach the builder.
enum Mark: string
{
    case Act = 'act';
    case Defer = 'defer';
    case Reject = 'reject';
}
