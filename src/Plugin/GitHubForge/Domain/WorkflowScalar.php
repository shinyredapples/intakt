<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowScalar
{
    public function __construct(
        public int $line,
        public string $text,
    ) {
    }
}
