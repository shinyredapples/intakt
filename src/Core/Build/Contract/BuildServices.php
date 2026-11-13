<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class BuildServices
{
    public const string ROUTING = 'intakt.build.routing';
}
