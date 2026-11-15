<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\TakeCheckedHead;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class CheckedHeadNotTaken extends RuntimeException implements ReportableFailure
{
    public static function at(string $directory, string $reason): self
    {
        return new self(sprintf('the build clone at %s did not take the checked head: %s', $directory, $reason));
    }
}
