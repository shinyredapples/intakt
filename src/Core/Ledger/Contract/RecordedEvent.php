<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

final readonly class RecordedEvent
{
    public function __construct(
        public int $sequence,
        public LedgerEventKind $kind,
        public string $payload,
        public \DateTimeImmutable $recordedAt,
    ) {
    }
}
