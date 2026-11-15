<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the bench builds it, not the container.
#[Exclude]
final readonly class BenchReview
{
    /**
     * @param list<BenchReviewerResult> $reviewers
     * @param list<Finding>             $actList    the judge's, for the whole round, because it merges the reviewers' findings
     */
    public function __construct(
        public string $change,
        public Decision $decision,
        public array $reviewers,
        public array $actList,
    ) {
    }
}
