<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class TaskNotQueued extends RuntimeException implements ReportableFailure
{
    public static function named(string $project, int $number): self
    {
        return new self(sprintf('Project "%s" has no open task #%d.', $project, $number));
    }
}
