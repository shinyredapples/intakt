<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Its own value, because a reviewer is always told the change and the open findings together and the request stays at three parts.
final readonly class ReviewBrief
{
    /**
     * @param list<Finding> $open
     */
    public function __construct(
        public ChangeUnderReview $change,
        public array $open,
    ) {
    }
}
