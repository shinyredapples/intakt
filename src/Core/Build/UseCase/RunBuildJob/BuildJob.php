<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Job\Contract\JobId;

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

    // Length-prefixed, so no project and branch pair names another pair's job; one id per pair, so the runner refuses a second build of a branch while one runs.
    public function jobId(): JobId
    {
        return new JobId('build-'.substr(hash('sha256', strlen($this->project).':'.$this->project.$this->branch), 0, 40));
    }
}
