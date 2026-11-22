<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;
use Symfony\Component\Uid\Uuid;

final class HaltLedger implements Ledger
{
    /** @var list<RecordedEvent> */
    private array $recorded = [];

    private bool $refusing = false;

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if ($this->refusing) {
            return AppendResult::failed('the ledger is down');
        }

        $id = Uuid::v7();
        $this->recorded[] = new RecordedEvent($id, $event, new DateTimeImmutable('2026-11-21T09:00:00Z'));

        return AppendResult::appended($id);
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents($this->recorded);
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind)));
    }

    /**
     * @param list<string> $texts
     */
    #[Override]
    public function readMentioning(array $texts): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    /**
     * @param list<LedgerEventKind> $kinds
     * @param list<string>          $texts
     */
    #[Override]
    public function readOfKindsMentioning(array $kinds, array $texts): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    public function refuseAppends(): void
    {
        $this->refusing = true;
    }

    /**
     * @return list<LedgerEventKind>
     */
    public function kinds(): array
    {
        return array_map(static fn (RecordedEvent $recorded): LedgerEventKind => $recorded->event->kind, $this->recorded);
    }

    /**
     * @return list<string>
     */
    public function payloads(): array
    {
        return array_map(static fn (RecordedEvent $recorded): string => $recorded->event->payload, $this->recorded);
    }
}
