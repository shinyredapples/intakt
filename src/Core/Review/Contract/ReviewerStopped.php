<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;

final readonly class ReviewerStopped
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public Tier $tier,
    ) {
    }
}
