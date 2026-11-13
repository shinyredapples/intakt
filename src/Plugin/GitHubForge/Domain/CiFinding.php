<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class CiFinding
{
    public function __construct(
        public WorkflowSide $side,
        public string $path,
        public WorkflowFinding $finding,
    ) {
    }
}
