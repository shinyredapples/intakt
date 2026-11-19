<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class HandBackServices
{
    public const string DEPENDENCY_SNAPSHOTS = 'intakt.hand_back.dependency_snapshots';
}
