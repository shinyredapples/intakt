<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// An exception, never a step's outcome, because the refusal comes before the first step and no step may read as passed.
final class PushRemoteRefused extends RuntimeException implements ReportableFailure
{
    public static function because(string $refusal): self
    {
        return new self($refusal);
    }
}
