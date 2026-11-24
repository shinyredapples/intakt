<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// Nothing was pushed, because the caller sends the branch back to the builder to merge main.
final readonly class MainConflicts
{
    public function __construct(
        public Paths $paths,
    ) {
    }
}
