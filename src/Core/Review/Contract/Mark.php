<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A P1 or P2 finding is act whatever the judge answers; defer and reject apply to a P3 only.
enum Mark: string
{
    case Act = 'act';
    case Defer = 'defer';
    case Reject = 'reject';
}
