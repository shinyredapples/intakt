<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;

final readonly class ChangeDescription
{
    public const string FULL_REVIEW_LABEL = 'review:full';

    /**
     * @param non-negative-int $lines
     */
    public function __construct(
        public int $lines,
        public Paths $paths,
        public Labels $labels,
    ) {
    }

    // The last model label wins, because Labels keeps one label per family in the order it was applied.
    public function startTier(): ?Tier
    {
        $tiers = array_filter(array_map(Tier::fromLabel(...), [...$this->labels]));

        return array_last($tiers);
    }

    public function wantsFullReview(): bool
    {
        return $this->labels->contains(self::FULL_REVIEW_LABEL);
    }
}
