<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

final readonly class LedgerEvent
{
    // The subject is stored in its own indexed column, so readOfKindAbout() reads the events of one subject without reading the rest of the ledger.
    public function __construct(
        public LedgerEventKind $kind,
        public string $payload,
        public ?string $subject = null,
    ) {
    }
}
