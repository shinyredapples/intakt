<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class BranchCleanupServices
{
    public const string PROJECTS = 'intakt.branch_cleanup.projects';
}
