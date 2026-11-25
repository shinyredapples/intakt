<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;

// Null for every job, because nothing feeds the progress rule while a build runs until the watch of #3965 replaces this.
final readonly class UnwatchedBuilds implements BuildStops
{
    public function causeFor(BuildJob $job): ?BuildStopCause
    {
        return null;
    }
}
