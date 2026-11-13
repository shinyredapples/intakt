<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowEntry
{
    public function __construct(
        public string $key,
        public int $line,
        public WorkflowScalar|WorkflowMapping|WorkflowSequence $value,
    ) {
    }
}
