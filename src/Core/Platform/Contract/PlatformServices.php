<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// The container ids and parameter names several modules share, so config and code never spell one twice.
final readonly class PlatformServices
{
    public const string GITLEAKS = 'intakt.gitleaks';
}
