<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use NoDiscard;

interface JobRunner
{
    // The container, its proxy and its network are gone when this returns or throws.
    #[NoDiscard('the outcome holds the end cause and the start check')]
    public function run(JobSpec $spec): JobOutcome;
}
