<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\QueryMessage;
use Intakt\Plugin\Forge\Contract\CommitId;

// Boots a head with the harness of main's commit, because a head that changes the harness must not certify itself.
final readonly class BootHarnessAtMain implements QueryMessage
{
    public function __construct(
        public BootAt $head,
        public CommitId $main,
    ) {
    }
}
