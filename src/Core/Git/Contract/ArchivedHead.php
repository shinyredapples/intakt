<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// Names the archive by project, because each project keeps its archived heads in a bare repository of its own.
final readonly class ArchivedHead
{
    public function __construct(
        public string $project,
        public BranchHead $head,
    ) {
    }
}
