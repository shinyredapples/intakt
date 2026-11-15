<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;

// The payload stays the stored text, because a replay must append exactly what the ledger held.
final readonly class RecordedLedgerEvent
{
    public function __construct(
        public string $id,
        public LedgerEventKind $kind,
        public string $payload,
        public DateTimeImmutable $recordedAt,
    ) {
    }

    public static function of(RecordedEvent $recorded): self
    {
        return new self($recorded->id->toRfc4122(), $recorded->event->kind, $recorded->event->payload, $recorded->recordedAt);
    }
}
