<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// What the setup wizard's steps read from the install: the AI account step, the project check and the first review.
final readonly class FirstRun
{
    public function __construct(
        public ProviderStep $providers,
        public ProjectStep $project,
        public FirstReviewStep $firstReview,
    ) {
    }

    // Whether the Ready step can start or open a live review now, so the demo line never promises an action the step does not offer.
    public function offersALiveReview(): bool
    {
        return null !== $this->firstReview->round() || $this->firstReview->sampleCanStart;
    }
}
