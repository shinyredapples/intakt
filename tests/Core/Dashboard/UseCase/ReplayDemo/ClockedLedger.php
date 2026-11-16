<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

// Stamps each row with the clock's moment at its append, so a test reads when the replay wrote it without waiting.
final class ClockedLedger implements Ledger
{
    /** @var list<RecordedEvent> */
    private array $recorded = [];

    private ?int $failingAppend = null;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function failAppend(int $number): void
    {
        $this->failingAppend = $number;
    }

    public function append(LedgerEvent $event): AppendResult
    {
        if (count($this->recorded) + 1 === $this->failingAppend) {
            return AppendResult::failed('disk full');
        }

        $id = Uuid::v7();
        $this->recorded[] = new RecordedEvent($id, $event, $this->clock->now());

        return AppendResult::appended($id);
    }

    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents($this->recorded);
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind)));
    }
}
