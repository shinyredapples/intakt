<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// Kept apart from the mirror because the mirror is a cache that is cloned again when lost, while the archive lives on the data volume.
interface BranchArchive
{
    /**
     * @throws BranchNotArchived
     */
    public function archive(MirroredHead $head): ArchivedBranch;
}
