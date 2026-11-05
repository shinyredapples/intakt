<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Shadow\Contract\ProjectMode;

final readonly class VerdictReceived
{
    public function __construct(
        public string $round,
        public string $reviewer,
        public string $role,
        public Tier $tier,
        public Decision $decision,
        public int $findings,
        public ProjectMode $mode,
    ) {
    }
}
