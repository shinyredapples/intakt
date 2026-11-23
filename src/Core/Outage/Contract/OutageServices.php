<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class OutageServices
{
    public const string PROBE_TIMEOUT = 'intakt.outage.probe_timeout';
}
