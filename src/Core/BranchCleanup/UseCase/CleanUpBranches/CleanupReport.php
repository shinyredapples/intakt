<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchHead;

final readonly class CleanupReport
{
    /**
     * @param list<BranchWarning>  $warned
     * @param list<ArchivedBranch> $deleted
     * @param list<BranchHead>     $stale
     * @param list<string>         $failures
     */
    public function __construct(
        public string $project,
        public array $warned,
        public array $deleted,
        public array $stale,
        public array $failures,
    ) {
    }
}
