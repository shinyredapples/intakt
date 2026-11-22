<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Ledger\Contract\Mentions;
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

    private float $secondsPerAppend = 0.0;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function failAppend(int $number): void
    {
        $this->failingAppend = $number;
    }

    // Each append then moves the clock on, as a database write takes time the replay must not add to the recorded gaps.
    public function takeSecondsPerAppend(float $seconds): void
    {
        $this->secondsPerAppend = $seconds;
    }

    public function append(LedgerEvent $event): AppendResult
    {
        if (count($this->recorded) + 1 === $this->failingAppend) {
            return AppendResult::failed('disk full');
        }

        $id = Uuid::v7();
        $this->recorded[] = new RecordedEvent($id, $event, $this->clock->now());
        $this->clock->sleep($this->secondsPerAppend);

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

    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => array_any([...$texts], static fn (string $text): bool => str_contains($recorded->event->payload, $text)))));
    }
}
