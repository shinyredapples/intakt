<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Tier;

final readonly class TierChoice
{
    public function __construct(
        public Tier $tier,
        public TierReason $reason,
    ) {
    }
}
