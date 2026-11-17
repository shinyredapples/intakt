<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Read from the steps below it, so the two ways to see a review never say something the steps contradict; excluded from the container because FirstRunSteps builds one per page.
#[Exclude]
final readonly class DemoChoices
{
    public function __construct(
        private ProviderStep $providers,
        private FirstReviewStep $firstReview,
    ) {
    }

    public function liveReviewNext(): string
    {
        return match (true) {
            null !== $this->firstReview->round() => 'The demo review has run: step 3 opens its page.',
            StepState::Done !== $this->providers->state() => 'Paste the key or token in step 1, restart Intakt, then press "Start the first review" in step 3: it starts the demo review and opens its live page.',
            $this->firstReview->sampleCanStart => 'Press "Start the first review" in step 3: it starts the demo review and opens its live page.',
            default => 'Restart Intakt, so the sample project gets its reviewers, then press "Start the first review" in step 3.',
        };
    }
}
