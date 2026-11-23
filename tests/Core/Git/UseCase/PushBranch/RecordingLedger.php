<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use LogicException;
use Override;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

// Reads back what it was given, in order, and can start to refuse, so a test can end a push at any phase boundary.
final class RecordingLedger implements Ledger
{
    public int $accepts = PHP_INT_MAX;

    /** @var list<LedgerEvent> */
    private array $events = [];

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if (count($this->events) >= $this->accepts) {
            return AppendResult::failed('the ledger is down');
        }

        $this->events[] = $event;

        return AppendResult::appended(Uuid::v7());
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return $this->recorded($this->events);
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->recorded(array_values(array_filter($this->events, static fn (LedgerEvent $event): bool => $event->kind === $kind)));
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->recorded(array_values(array_filter($this->events, static fn (LedgerEvent $event): bool => $event->kind === $kind && $event->subject === $subject)));
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->recorded(array_values(array_filter($this->events, static fn (LedgerEvent $event): bool => $event->kind === $kind && null === $event->subject)));
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->recorded(array_values(array_filter($this->events, static fn (LedgerEvent $event): bool => array_any([...$texts], static fn (string $text): bool => str_contains($event->payload, $text)))));
    }

    /**
     * @return list<LedgerEventKind>
     */
    public function kinds(): array
    {
        return array_map(static fn (LedgerEvent $event): LedgerEventKind => $event->kind, $this->events);
    }

    /**
     * @return array<string, string>
     */
    public function payloadOf(LedgerEventKind $kind): array
    {
        return array_first($this->payloadsOf($kind)) ?? throw new LogicException('No such event was recorded.');
    }

    /**
     * @return list<array<string, string>>
     */
    public function payloadsOf(LedgerEventKind $kind): array
    {
        $payloads = [];

        foreach ($this->events as $event) {
            if ($event->kind === $kind) {
                /** @var array<string, string> $payload */
                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param list<LedgerEvent> $events
     */
    private function recorded(array $events): RecordedEvents
    {
        $moment = new MockClock('2026-11-25 10:00:00 UTC')->now();

        return new RecordedEvents(array_map(static fn (LedgerEvent $event): RecordedEvent => new RecordedEvent(Uuid::v7(), $event, $moment), $events));
    }
}
