<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

final readonly class HaltClearedPayload
{
    public function __construct(
        public string $card,
    ) {
    }
}
