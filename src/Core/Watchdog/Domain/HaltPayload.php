<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Violation;

final readonly class HaltPayload
{
    public function __construct(
        public string $project,
        public string $commit,
        public Violation $violation,
        // Main's head when the halt was raised, because two rewrites that dropped the same head differ only in what replaced it; optional, because cards written before it was recorded must still halt.
        public ?string $head = null,
    ) {
    }
}
