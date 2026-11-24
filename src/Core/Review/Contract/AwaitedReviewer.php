<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A reviewer the round still waits on, or one that ended blocked, named by its role and tier, so a caller can say what it waits on (issue #2803), and by its id, so a later poll recognizes it; excluded because the round builds it per poll.
#[Exclude]
final readonly class AwaitedReviewer
{
    public function __construct(
        public string $role,
        public Tier $tier,
        public string $id,
    ) {
    }
}
