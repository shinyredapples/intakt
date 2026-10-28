<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

final readonly class LedgerEvent
{
    public function __construct(
        public LedgerEventKind $kind,
        public string $payload,
    ) {
    }
}
