<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class TaskIssue
{
    public function __construct(
        public string $project,
        public int $number,
    ) {
    }
}
