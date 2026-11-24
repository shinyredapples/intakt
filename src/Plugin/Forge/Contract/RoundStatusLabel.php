<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Only these two, because the other status values describe a task and a change under review is never one of them.
enum RoundStatusLabel: string
{
    case InReview = 'status:in-review';
    case WaitingOnYou = 'status:waiting-on-you';

    public const string FAMILY_PREFIX = 'status:';
}
