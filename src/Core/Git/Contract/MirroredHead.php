<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// Names the mirror by project because the archive copies the head's objects from it.
final readonly class MirroredHead
{
    public function __construct(
        public string $project,
        public BranchHead $head,
    ) {
    }
}
