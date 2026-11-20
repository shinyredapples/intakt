<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class MainViolation
{
    public function __construct(
        public string $commit,
        public Violation $violation,
    ) {
    }
}
