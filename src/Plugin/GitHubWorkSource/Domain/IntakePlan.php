<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IntakePlan
{
    // The waits of each ready task as the rules resolved them, a trusted pull request standing for the tasks it closes, because the pick checks later failures against those and not against the raw numbers.
    /**
     * @param list<QueuedIssue>             $ready
     * @param list<HeldIssue>               $held
     * @param array<int, int>               $pullRequests
     * @param array<int, list<int>>         $dependsOn
     * @param list<int>             $openPullRequests
     * @param array<int, list<int>> $closingPullRequests
     */
    public function __construct(
        public array $ready,
        public array $held,
        public array $pullRequests,
        public array $dependsOn,
        public array $openPullRequests,
        public array $closingPullRequests,
    ) {
    }
}
