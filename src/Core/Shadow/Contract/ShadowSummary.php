<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

// Counts only, because a limit or reset time in a summary would leak account state into the report.
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
