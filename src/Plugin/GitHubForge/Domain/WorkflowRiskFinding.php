<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowRiskFinding
{
    // Line 0 stands for the whole file, since a file that cannot be read has no line to name.
    public function __construct(
        public string $path,
        public int $line,
        public WorkflowRiskKind $kind,
        public string $detail,
    ) {
    }
}
