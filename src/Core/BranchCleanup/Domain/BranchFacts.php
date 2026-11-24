<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\ForgeBranch;

// What the forge and the mirror say about one branch; a head the mirror lacks is null, so the branch is kept.
final readonly class BranchFacts
{
    public function __construct(
        public ForgeBranch $branch,
        public ?BranchChange $change,
        public ?MirroredHead $head,
    ) {
    }
}
