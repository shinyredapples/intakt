<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Contract\Connectivity;
use Intakt\Core\Outage\Contract\Reachability;
use Override;

final readonly class ProbedConnectivity implements Connectivity
{
    /**
     * @param list<Probe> $probes
     */
    public function __construct(
        private array $probes,
    ) {
    }

    // Stops at the first probe that answers, so an online check costs one request.
    #[Override]
    public function reachability(): Reachability
    {
        return array_any($this->probes, static fn (Probe $probe): bool => $probe->answers())
            ? Reachability::Online
            : Reachability::Offline;
    }
}
