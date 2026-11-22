<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IntakePlan
{
    /**
     * @param list<QueuedIssue>     $ready
     * @param list<HeldIssue>       $held
     * @param array<int, int>       $pullRequests
     * @param array<int, list<int>> $dependsOn    the tasks each ready task waits for, a trusted pull request resolved to the tasks it closes
     */
    public function __construct(
        public array $ready,
        public array $held,
        public array $pullRequests,
        public array $dependsOn,
    ) {
    }
}
