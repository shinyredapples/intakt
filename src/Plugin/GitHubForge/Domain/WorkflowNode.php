<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowNode
{
    // No key for a list item holding a plain value, since only its value can be read.
    public function __construct(
        public int $indent,
        public ?string $key,
        public WorkflowValue $value,
    ) {
    }
}
