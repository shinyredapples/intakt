<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\RestoreBranch;

use Intakt\Core\BranchCleanup\Infrastructure\ProjectForges;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

final readonly class RestoreBranch
{
    public function __construct(
        private ProjectForges $projects,
        private BranchArchive $archive,
    ) {
    }

    /**
     * @throws BranchNotArchived
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    public function restore(string $project, string $branch): ArchivedBranch
    {
        $archived = $this->archive->latestOf($project, $branch) ?? throw BranchNotArchived::because(sprintf('the mirror of "%s" keeps no archive of the branch "%s"', $project, $branch));
        $this->projects->forgeOf($project)->restoreBranch($project, new BranchRestore($archived->branch, new CommitId($archived->commit), $archived->ref, $archived->mirror));

        return $archived;
    }
}
