<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;

final readonly class ReviewerModel
{
    public function __construct(
        public Tier $tier,
        public Provider $provider,
    ) {
    }
}
