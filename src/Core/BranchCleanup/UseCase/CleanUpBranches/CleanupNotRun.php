<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class CleanupNotRun extends RuntimeException implements ReportableFailure
{
    public static function alreadyRunning(string $project): self
    {
        return new self(sprintf('A branch cleanup of project "%s" is already running.', $project));
    }

    public static function withoutDefaultBranch(string $project): self
    {
        return new self(sprintf('The forge names no default branch of project "%s", so no branch is judged.', $project));
    }
}
