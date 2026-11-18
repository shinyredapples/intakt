<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

// Both answers are mapped together, because a workflow run names the check suite it runs in and that suite must share the run's workflow.
final readonly class HeadCiAnswers
{
    /**
     * @param array<mixed> $workflowRuns
     * @param array<mixed> $checkSuites
     */
    public function __construct(
        public array $workflowRuns,
        public array $checkSuites,
    ) {
    }
}
