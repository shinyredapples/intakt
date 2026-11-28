<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Fixed words and no cause, as for a task not parked, because the database's message may quote the stored question.
final class ParkedTaskNotCleared extends RuntimeException implements ReportableFailure
{
    public static function for(string $project, int $task): self
    {
        return new self(sprintf('Issue %d of project "%s" is still parked: the database did not remove its question', $task, $project));
    }
}
