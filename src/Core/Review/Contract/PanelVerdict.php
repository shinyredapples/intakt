<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;

final readonly class PanelVerdict
{
    public function __construct(
        public Tier $tier,
        public Verdict $verdict,
        public ReviewLane $lane = ReviewLane::Code,
    ) {
    }

    public function approves(): bool
    {
        return Decision::Approve === $this->verdict->verdict;
    }

    public function isStrong(): bool
    {
        return Tier::Strong === $this->tier;
    }
}
