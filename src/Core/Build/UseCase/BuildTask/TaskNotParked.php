<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class TaskNotParked extends RuntimeException implements ReportableFailure
{
    // Fixed words with the cause kept apart, because a database error may quote the row, and the row holds the builder's untrusted question.
    public static function for(BuildTaskRequest $request, Throwable $cause): self
    {
        return new self(sprintf('issue %d of project "%s" could not be parked', $request->issue, $request->project), previous: $cause);
    }
}
