<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

final readonly class SeenCommit
{
    public function __construct(
        public string $name,
        public SignatureStatus $signature,
    ) {
    }
}
