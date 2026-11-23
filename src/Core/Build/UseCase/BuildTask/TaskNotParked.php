<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Fixed words and no cause, because the database's message may quote the question, which the builder wrote.
final class TaskNotParked extends RuntimeException implements ReportableFailure
{
    public static function for(string $project, int $task): self
    {
        return new self(sprintf('Issue %d of project "%s" was not parked: the database did not store its question', $task, $project));
    }
}
