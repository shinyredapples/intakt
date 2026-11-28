<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Throwable;

final class BuildCloneNotMade extends RuntimeException implements ReportableFailure
{
    public static function at(string $directory, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('the build clone at %s was not made: %s', $directory, $reason), 0, $previous);
    }
}
