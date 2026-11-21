<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class UpNextRound
{
    // The reviewers are the roles running now, empty in every other state, because that is all the list tells of a round.
    /**
     * @param list<string> $reviewers
     */
    public function __construct(
        public string $round,
        public string $project,
        public string $change,
        public UpNextState $state,
        public array $reviewers,
    ) {
    }

    public function status(): string
    {
        return match ($this->state) {
            UpNextState::Running => 'running: '.implode(', ', $this->reviewers),
            UpNextState::Judging => 'judging',
            UpNextState::BetweenReviewers => 'in progress, no reviewer running',
            UpNextState::Waiting => 'waiting to start',
        };
    }
}
