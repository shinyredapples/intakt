<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class BranchNotArchived extends RuntimeException implements ReportableFailure
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('The branch archive could not be used: %s.', $reason), 0, $previous);
    }
}
