<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Plugin\Forge\Contract\CommitId;

// The exact commit that boots, because a caller that checked a head must get the boot result of that commit, not of wherever the branch points by then.
final readonly class BootAt
{
    public function __construct(
        public ChangeSource $source,
        public CommitId $commit,
    ) {
    }
}
