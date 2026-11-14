<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class HeldIssue
{
    public function __construct(
        public QueuedIssue $issue,
        public string $reason,
    ) {
    }
}
