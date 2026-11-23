<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\QueryMessage;
use Intakt\Plugin\Forge\Contract\CommitId;

// Answered by the plugin that starts the round, so the identity is read from the same repository and with the same terms the round recorded its own with.
final readonly class IdentityAtHead implements QueryMessage
{
    public function __construct(
        public StartReviewAtHead $round,
        public CommitId $reviewed,
    ) {
    }
}
