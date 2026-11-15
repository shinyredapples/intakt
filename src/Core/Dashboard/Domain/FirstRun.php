<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class FirstRun
{
    public function __construct(
        public ProviderStep $providers,
        public ProjectStep $project,
        public FirstReviewStep $firstReview,
        public AgentStep $agent,
    ) {
    }

    /**
     * @return list<StepState>
     */
    public function states(): array
    {
        return [$this->providers->state(), $this->project->state(), $this->firstReview->state(), $this->agent->state()];
    }

    public function done(): int
    {
        return count(array_filter($this->states(), static fn (StepState $state): bool => StepState::Done === $state));
    }

    // A step that is not needed is left out, so the progress can reach every step.
    public function needed(): int
    {
        return count(array_filter($this->states(), static fn (StepState $state): bool => StepState::NotNeeded !== $state));
    }
}
