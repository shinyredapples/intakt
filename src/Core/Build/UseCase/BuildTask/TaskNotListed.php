<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class TaskNotListed extends RuntimeException implements ReportableFailure
{
    public static function of(string $project, int $number): self
    {
        return new self(sprintf('Project "%s" has no open issue #%d.', $project, $number));
    }
}
