<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ArchiveBranch;

use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

final readonly class DeleteBranchAfterArchive
{
    public function __construct(
        private BranchArchive $archive,
        private ForgeBranches $branches,
    ) {
    }

    /**
     * @throws BranchNotArchived
     * @throws ForgeWriteFailed
     */
    public function delete(string $project, ForgeBranch $branch): ArchivedBranch
    {
        // Archived first and deleted only afterwards, so a failed archive throws before the forge is called and the branch stays.
        $archived = $this->archive->archive(new MirroredHead($project, new BranchHead($branch->name, $branch->head->hash)));
        $this->branches->delete($branch);

        return $archived;
    }
}
