<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// The issue of the task a head was built for, in the GitHub repository of the project's watch section.
final readonly class TaskIssue
{
    public function __construct(
        public string $project,
        public int $number,
    ) {
    }
}
