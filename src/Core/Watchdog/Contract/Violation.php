<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class Violation
{
    // The commit is the one on main that broke the rule; for a rewrite it is the last head the watchdog had checked, which main no longer holds.
    public function __construct(
        public ViolationKind $kind,
        public string $commit,
    ) {
    }
}
