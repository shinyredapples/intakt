<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class BuildCheckoutFailed extends RuntimeException implements ReportableFailure
{
    public static function because(Throwable $failure): self
    {
        return new self($failure->getMessage(), 0, $failure);
    }
}
