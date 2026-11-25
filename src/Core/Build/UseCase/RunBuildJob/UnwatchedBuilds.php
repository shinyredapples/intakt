<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;

// TODO(#3965): replaced by the watch that feeds the progress rule while the build runs; until then no build is stopped.
final readonly class UnwatchedBuilds implements BuildStops
{
    public function causeFor(BuildJob $job): ?BuildStopCause
    {
        return null;
    }
}
