<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

interface BuildRouting
{
    /**
     * @throws BuildRoutingNotConfigured
     */
    public function tierFor(string $project, BuildFacts $facts): TierChoice;
}
