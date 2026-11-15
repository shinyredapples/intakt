<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class FirstRun
{
    public function __construct(
        public ProviderStep $providers,
        public ProjectStep $project,
        public AgentStep $agent,
    ) {
    }

    /**
     * @return list<StepState>
     */
    public function states(): array
    {
        return [$this->providers->state(), $this->project->state(), $this->agent->state()];
    }

    public function done(): int
    {
        return count(array_filter($this->states(), static fn (StepState $state): bool => StepState::Done === $state));
    }
}
