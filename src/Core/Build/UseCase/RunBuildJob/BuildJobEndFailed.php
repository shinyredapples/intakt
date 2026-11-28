<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Carries the stop cause of a job that failed after the runner was called, because the cause is read once and the Job contract's failures know nothing of a build's stop.
final class BuildJobEndFailed extends RuntimeException implements ReportableFailure
{
    private function __construct(string $message, DockerEngineFailed|JobEventNotRecorded $previous, public readonly ?BuildStopCause $stopCause)
    {
        parent::__construct($message, previous: $previous);
    }

    // The original's message unchanged, because the failed build_job row records the same text as before the stop cause was kept.
    public static function after(DockerEngineFailed|JobEventNotRecorded $failure, ?BuildStopCause $stopCause): self
    {
        return new self($failure->getMessage(), $failure, $stopCause);
    }
}
