<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

final readonly class PullRequestTextOutcome
{
    public function __construct(
        public PullRequestTextVerdict $verdict,
        public int $evidenceItems,
        public MissingEvidenceItems $missingItems,
        public int $bodyCharacters,
    ) {
    }

    public function passed(): bool
    {
        return PullRequestTextVerdict::Passed === $this->verdict;
    }
}
