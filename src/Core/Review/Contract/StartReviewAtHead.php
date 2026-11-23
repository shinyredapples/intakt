<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\CommandMessage;
use Intakt\Plugin\Forge\Contract\CommitId;

// Pins the commit the round reviews, because a caller that checked a head (the watcher read its CI) must get a round on that commit, not on wherever the branch points by then.
final readonly class StartReviewAtHead implements CommandMessage
{
    public function __construct(
        public StartReview $start,
        public CommitId $head,
    ) {
    }
}
