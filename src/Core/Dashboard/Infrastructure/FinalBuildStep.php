<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Ledger\Contract\PayloadFields;

// Apart from the ledger read, because a passed push ends a run only when it was recorded before the pull request step existed: the build names the step after the push in its row since then, so a passed push without that name is a finished older run.
final readonly class FinalBuildStep
{
    public static function is(BuildStep $step, BuildStepOutcome $outcome, PayloadFields $payload): bool
    {
        if (array_last(BuildStep::cases()) === $step) {
            return true;
        }

        return BuildStep::Push === $step && BuildStepOutcome::Passed === $outcome && !$payload->has('next');
    }
}
