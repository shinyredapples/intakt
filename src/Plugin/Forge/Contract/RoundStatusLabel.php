<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Review\Contract\ReviewStatus;

// Only these two, because the other status values describe a task and a change under review is never one of them.
enum RoundStatusLabel: string
{
    case InReview = 'status:in-review';
    case WaitingOnYou = 'status:waiting-on-you';

    public const string FAMILY_PREFIX = 'status:';

    // Derived from the stored status, so a status the round gains cannot be mirrored under a label spelled twice.
    public static function of(ReviewStatus $status): self
    {
        return self::from(self::FAMILY_PREFIX.$status->value);
    }
}
