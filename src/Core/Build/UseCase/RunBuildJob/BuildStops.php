<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;

// Asked after the job ended, because the watch that ends a running build keeps the cause it stopped it for.
interface BuildStops
{
    public function causeFor(BuildJob $job): ?BuildStopCause;
}
