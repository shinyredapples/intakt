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
        // The round's number, because from the second round on the goalpost rule lowers severities, and the guards that refuse an approval must see a verdict as the round will count it; required, so no caller can leave the rule unapplied by leaving it out.
        public int $round,
    ) {
    }
}
