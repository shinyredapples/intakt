<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// An outcome, not a failure, because the remote answered and the ledger holds it as push_refused; the reason names no remote address.
final readonly class RefusedPush
{
    public function __construct(
        public string $reason,
    ) {
    }
}
