<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use NoDiscard;

interface JobRunner
{
    // A caller can rely on the container, its proxy and its network being gone when this returns or throws, because a leftover holds memory and a name.
    #[NoDiscard('the outcome holds the end cause and the start check')]
    public function run(JobSpec $spec): JobOutcome;
}
