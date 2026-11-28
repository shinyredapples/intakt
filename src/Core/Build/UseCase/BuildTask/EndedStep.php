<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildStopCause;

// How one step of a run ended, as its build_step_ended row records it; a stop cause only for a build job whose session was stopped, so the limits can be tuned from the recorded causes.
final readonly class EndedStep
{
    public function __construct(
        public BuildStep $step,
        public BuildStepOutcome $outcome,
        public ?string $failure,
        public ?BuildStopCause $stopCause,
        // The tokens a build job used, so the task's next build job counts them toward its per-task total.
        public int $tokens = 0,
    ) {
    }

    public static function of(BuildStep $step, ?string $failure, ?BuildStopCause $stopCause = null, int $tokens = 0): self
    {
        return new self($step, null === $failure ? BuildStepOutcome::Passed : BuildStepOutcome::Failed, $failure, $stopCause, $tokens);
    }
}
