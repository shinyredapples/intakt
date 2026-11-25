<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// The project names the archive the head is pushed from, because a forge is bound to a repository and the archive to a project.
final readonly class BranchRestore
{
    public function __construct(
        public string $project,
        public string $name,
        public CommitId $head,
    ) {
    }
}
