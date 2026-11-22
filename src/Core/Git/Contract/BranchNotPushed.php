<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class BranchNotPushed extends RuntimeException implements ReportableFailure
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('The branch was not pushed: '.$reason, 0, $previous);
    }
}
