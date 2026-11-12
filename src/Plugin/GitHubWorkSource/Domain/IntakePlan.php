<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IntakePlan
{
    /**
     * @param list<QueuedIssue> $ready
     * @param list<HeldIssue>   $held
     */
    public function __construct(
        public array $ready,
        public array $held,
    ) {
    }
}
