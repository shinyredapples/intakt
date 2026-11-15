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
     * @param list<Finding>             $actList
     */
    public function __construct(
        public string $change,
        public Decision $decision,
        public array $reviewers,
        // The judge's for the whole round, because it merges the reviewers' findings.
        public array $actList,
    ) {
    }

    public function hasHarnessFailure(): bool
    {
        return array_any($this->reviewers, static fn (BenchReviewerResult $reviewer): bool => $reviewer->verdict->isHarnessFailure());
    }
}
