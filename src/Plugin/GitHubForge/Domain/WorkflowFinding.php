<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowFinding
{
    public function __construct(
        public WorkflowRisk $risk,
        public int $line,
        public string $detail,
    ) {
    }
}
