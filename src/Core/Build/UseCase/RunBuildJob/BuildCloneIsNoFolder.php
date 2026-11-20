<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class BuildCloneIsNoFolder extends RuntimeException implements ReportableFailure
{
    public static function at(string $path): self
    {
        return new self(sprintf('The build clone "%s" is no folder.', $path));
    }
}
