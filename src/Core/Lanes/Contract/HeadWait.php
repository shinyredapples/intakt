<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Contract;

enum HeadWait: string
{
    case ForReviewer = 'for_reviewer';
    case InReview = 'in_review';
    case ForCi = 'for_ci';
    case ForRunner = 'for_runner';
    case ForLanding = 'for_landing';

    // Only a reviewer can move these heads on, so building more beside them only makes work go stale.
    public function holdsTheReviewCap(): bool
    {
        return match ($this) {
            self::ForReviewer, self::InReview => true,
            self::ForCi, self::ForRunner, self::ForLanding => false,
        };
    }
}
