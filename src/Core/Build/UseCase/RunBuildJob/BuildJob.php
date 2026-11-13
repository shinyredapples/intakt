<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildBrief;

final readonly class BuildJob
{
    public function __construct(
        public string $project,
        // A build clone made from the project's mirror, so the job sees main and the task's branch and no other branch.
        public string $clone,
        public string $branch,
        public BuildBrief $brief,
        // The planner's choice, so the model comes from the project's tier map, never from the caller.
        public Tier $tier,
        public string $image,
    ) {
    }
}
