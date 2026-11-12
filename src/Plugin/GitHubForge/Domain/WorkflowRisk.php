<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowRisk
{
    /** @param list<WorkflowRiskFinding> $findings */
    public function __construct(
        public array $findings,
    ) {
    }

    public function isRisky(): bool
    {
        return [] !== $this->findings;
    }
}
