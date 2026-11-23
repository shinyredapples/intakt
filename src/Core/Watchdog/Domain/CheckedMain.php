<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Classification;

final readonly class CheckedMain
{
    public function __construct(
        public Classification $classification,
        // Read before the walk, so two rewrites that dropped the same head are told apart by what replaced it.
        public string $head,
    ) {
    }
}
