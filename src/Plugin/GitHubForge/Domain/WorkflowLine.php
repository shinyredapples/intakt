<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowLine
{
    public function __construct(
        public int $number,
        public int $indent,
        public string $content,
    ) {
    }
}
