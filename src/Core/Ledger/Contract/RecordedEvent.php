<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final readonly class RecordedEvent
{
    public function __construct(
        public Uuid $id,
        public LedgerEvent $event,
        public DateTimeImmutable $recordedAt,
    ) {
    }
}
