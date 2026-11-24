<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobRefused;
use NoDiscard;

// An interface, because the build task judges a bug head by these runs and other modules may use only this module's contract.
interface RegressionTests
{
    /**
     * @throws HandBackBranchRefused
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    #[NoDiscard]
    public function run(CheckJob $job): RegressionRuns|RefusedHandBack;
}
