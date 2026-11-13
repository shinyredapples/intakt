<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowSequence
{
    /**
     * @param list<WorkflowScalar|WorkflowMapping|WorkflowSequence> $items
     */
    public function __construct(
        public int $line,
        public array $items,
    ) {
    }
}
