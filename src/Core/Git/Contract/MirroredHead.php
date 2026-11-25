<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// A branch head the project's mirror holds, which is where its objects are copied from.
final readonly class MirroredHead
{
    public function __construct(
        public string $project,
        public BranchHead $head,
    ) {
    }
}
