<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// Archive refs live in the project's mirror only, because the mirror is never pushed and a deleted branch must stay restorable.
interface BranchArchive
{
    /**
     * @throws BranchNotArchived
     */
    public function refresh(string $project, string $remote): void;

    // Null when the mirror does not hold the head, because a head Intakt cannot archive must not be deleted.
    public function headOf(string $project, BranchHead $head, string $base): ?MirroredHead;

    /**
     * @throws BranchNotArchived
     */
    public function archive(string $project, BranchHead $head): ArchivedBranch;

    /**
     * @throws BranchNotArchived
     */
    public function latestOf(string $project, string $branch): ?ArchivedBranch;
}
