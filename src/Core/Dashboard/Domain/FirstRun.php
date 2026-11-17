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
        public ?DemoChoices $choices,
    ) {
    }

    /**
     * @return list<StepState>
     */
    public function states(): array
    {
        return [$this->providers->state(), $this->project->state(), $this->firstReview->state(), $this->agent->state()];
    }

    // A step that is not needed is never current, because there is nothing for the reader to do in it.
    public function current(): ?int
    {
        foreach ($this->states() as $index => $state) {
            if (StepState::Done !== $state && StepState::NotNeeded !== $state) {
                return $index;
            }
        }

        return null;
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
