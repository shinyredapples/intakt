<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class BuildStepNotRecorded extends RuntimeException implements ReportableFailure
{
    public static function for(BuildRun $run, BuildStep $step, string $reason): self
    {
        return new self(sprintf('The ledger did not record the %s step of issue %d of project "%s": %s', $step->value, $run->task, $run->project, $reason));
    }
}
