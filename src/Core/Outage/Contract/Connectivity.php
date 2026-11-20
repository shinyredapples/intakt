<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Contract;

use NoDiscard;

// Offline only when nothing outside answers, because one failing host is that host's outage, not this machine's.
interface Connectivity
{
    #[NoDiscard]
    public function reachability(): Reachability;
}
