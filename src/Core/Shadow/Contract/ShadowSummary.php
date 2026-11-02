<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

// Counts only: no limits, usage windows or reset times belong in a summary.
final readonly class ShadowSummary
{
    public function __construct(
        public int $heads,
        public int $matched,
        public int $loopOnly,
        public int $intaktOnly,
        public int $severityDisagreements,
        public int $intaktFailedReviews,
        public int $unreadableLoopReviews,
        public int $intaktReviews,
        public int $intaktTokens,
    ) {
    }
}
