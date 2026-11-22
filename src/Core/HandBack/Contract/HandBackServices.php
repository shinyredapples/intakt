<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class HandBackServices
{
    public const string DEPENDENCY_SNAPSHOTS_DIRECTORY = 'intakt.hand_back.dependency_snapshots_directory';

    public const string DEPENDENCY_SNAPSHOT_COUNT = 'intakt.hand_back.dependency_snapshot_count';
}
