<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class UpNextRound
{
    // The reviewers are the roles running now, empty for a round waiting to start, because that is all the list tells of a round.
    /**
     * @param list<string> $reviewers
     */
    public function __construct(
        public string $round,
        public string $project,
        public string $change,
        public array $reviewers,
    ) {
    }

    public function isRunning(): bool
    {
        return [] !== $this->reviewers;
    }
}
