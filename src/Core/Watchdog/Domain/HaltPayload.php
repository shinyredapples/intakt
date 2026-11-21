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
    ) {
    }
}
