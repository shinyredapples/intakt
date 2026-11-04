<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class ShadowServices
{
    public const string MODES = 'intakt.shadow.modes';
}
