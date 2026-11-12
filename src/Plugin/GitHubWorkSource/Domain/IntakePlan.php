<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IntakePlan
{
    /**
     * @param list<QueuedIssue> $ready
     * @param list<HeldIssue>   $held
     * @param array<int, int>   $pullRequests the open pull request of a task, by the task's number
     */
    public function __construct(
        public array $ready,
        public array $held,
        public array $pullRequests,
    ) {
    }
}
