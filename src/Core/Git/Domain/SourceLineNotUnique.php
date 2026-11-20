<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class SourceLineNotUnique extends RuntimeException implements ReportableFailure
{
    public static function inTask(): self
    {
        return new self('the task names more than one Source line, so the commit to build from is unclear');
    }
}
