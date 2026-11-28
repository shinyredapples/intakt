<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use NoDiscard;

interface JobRunner
{
    /**
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobOutputRefused
     * @throws JobEventNotRecorded
     */
    #[NoDiscard('the cause and the output are only reported through the result')]
    public function run(JobSpec $spec, ?JobWatch $watch = null): JobResult;
}
