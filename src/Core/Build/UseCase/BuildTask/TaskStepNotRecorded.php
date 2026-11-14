<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class TaskStepNotRecorded extends RuntimeException implements ReportableFailure
{
    public static function of(string $project, int $number, string $reason): self
    {
        return new self(sprintf('The ledger did not record the step of task #%d of project "%s": %s', $number, $project, $reason));
    }
}
