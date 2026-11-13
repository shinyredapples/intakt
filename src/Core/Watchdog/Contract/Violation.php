<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class Violation
{
    // For a rewrite the commit is the last head checked, because main no longer holds it and the new history has no single culprit.
    public function __construct(
        public ViolationKind $kind,
        public string $commit,
    ) {
    }
}
