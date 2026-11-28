<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;

// How one step of a run ended, as its build_step_ended row records it; a stop cause only for a build job whose session was stopped, so the limits can be tuned from the recorded causes, and the session's usage only for a build job whose log was read, so the per-task total can count it from the ledger.
final readonly class EndedStep
{
    public function __construct(
        public BuildStep $step,
        public BuildStepOutcome $outcome,
        public ?string $failure,
        public ?BuildStopCause $stopCause,
        public ?SessionUsage $usage = null,
    ) {
    }

    public static function of(BuildStep $step, ?string $failure, ?BuildStopCause $stopCause = null): self
    {
        return new self($step, null === $failure ? BuildStepOutcome::Passed : BuildStepOutcome::Failed, $failure, $stopCause);
    }

    public static function ofBuildJob(?string $failure, BuildJobOutcome $job): self
    {
        return new self(BuildStep::BuildJob, null === $failure ? BuildStepOutcome::Passed : BuildStepOutcome::Failed, $failure, $job->stopCause, $job->usage);
    }
}
