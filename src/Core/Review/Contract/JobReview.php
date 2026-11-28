<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Plugin\Forge\Contract\CommitId;

// Set only when the install has a container engine, so the reviewer runs in a review job against a clone of this exact head instead of in the app's own process.
final readonly class JobReview
{
    public function __construct(
        public CommitId $head,
        // The panel reviewer's role name, so two reviewers of one head run as two jobs.
        public string $reviewer,
    ) {
    }
}
