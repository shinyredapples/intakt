<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class FirstRun
{
    public function __construct(
        public ProviderStep $providers,
        public ProjectStep $project,
        public FirstReviewStep $firstReview,
    ) {
    }

    /**
     * @return list<StepState>
     */
    public function states(): array
    {
        return [$this->providers->state(), $this->project->state(), $this->firstReview->state()];
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

    // The role who does the current step: the maintainer keeps the key, the developer brings the project, the reviewer reads the first change.
    public function currentCharacter(): ?Character
    {
        return match ($this->current()) {
            0 => Character::Maintainer,
            1 => Character::Developer,
            2 => Character::CodeReviewer,
            default => null,
        };
    }

    // Whether a card below can start or open a live review now, so the hero line never promises an action no card offers.
    public function offersALiveReview(): bool
    {
        return null !== $this->firstReview->round() || $this->firstReview->sampleCanStart;
    }
}
