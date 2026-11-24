<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Where the archived head is kept travels with it, because a forge that cannot reach Intakt's mirror (GitHub) creates the branch from the commit it still holds, while a local one fetches the commit from the mirror.
#[Exclude]
final readonly class BranchRestore
{
    public function __construct(
        public string $branch,
        public CommitId $head,
        public string $archiveRef,
        public string $mirror,
    ) {
    }
}
