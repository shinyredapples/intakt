<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Carries the stop cause read once for the job, because the runner failed after the session was stopped and the failed build job row must still name the cause.
final class BuildJobFailed extends RuntimeException implements ReportableFailure
{
    public function __construct(
        DockerEngineFailed|JobEventNotRecorded $failure,
        public readonly ?BuildStopCause $stopCause,
    ) {
        parent::__construct($failure->getMessage(), 0, $failure);
    }
}
