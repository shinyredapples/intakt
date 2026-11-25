<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;

final readonly class ChangeDescription
{
    public const string FULL_REVIEW_LABEL = 'review:full';

    public const string TECHNICAL_WRITER_LABEL = 'review:technical_writer';

    /**
     * @param non-negative-int $lines
     */
    public function __construct(
        public int $lines,
        public Paths $paths,
        public Labels $labels,
        // The pages whose "In short" section the change touches, read by the forge from both sides of the diff because a summary can change outside the lines a hunk shows; empty where nobody read them.
        public Paths $summaries = new Paths(),
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

    public function asksForTechnicalWriter(): bool
    {
        return $this->labels->contains(self::TECHNICAL_WRITER_LABEL);
    }
}
