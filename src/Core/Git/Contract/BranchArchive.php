<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// The archive is Intakt's own repository on the data volume, never the mirror, which is a cache that is cloned again when lost.
interface BranchArchive
{
    /**
     * @throws BranchNotArchived
     */
    public function archive(MirroredHead $head): ArchivedBranch;
}
