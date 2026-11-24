<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// The two states a reviewed change shows on a forge with labels; the others of the status family belong to tasks, not to a change under review.
enum RoundStatusLabel: string
{
    case InReview = 'status:in-review';
    case WaitingOnYou = 'status:waiting-on-you';

    public const string FAMILY_PREFIX = 'status:';
}
