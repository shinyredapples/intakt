<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;
use Symfony\Component\Uid\Uuid;

final class InMemoryLedger implements Ledger
{
    /** @var list<LedgerEvent> */
    private array $events = [];

    public function __construct(private readonly int $accepted = PHP_INT_MAX)
    {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if (count($this->events) >= $this->accepted) {
            return AppendResult::failed('the ledger is down');
        }

        $this->events[] = $event;

        return AppendResult::appended(Uuid::v7());
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        $about = array_filter($this->events, static fn (LedgerEvent $event): bool => $kind === $event->kind && $subject === $event->subject);

        return new RecordedEvents(array_values(array_map(static fn (LedgerEvent $event): RecordedEvent => new RecordedEvent(Uuid::v7(), $event, new DateTimeImmutable('2026-11-28T12:00:00Z')), $about)));
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        $mentioning = array_filter($this->events, static fn (LedgerEvent $event): bool => in_array($event->kind, $kinds, true) && array_any([...$texts], static fn (string $text): bool => str_contains($event->payload, $text)));

        return new RecordedEvents(array_values(array_map(static fn (LedgerEvent $event): RecordedEvent => new RecordedEvent(Uuid::v7(), $event, new DateTimeImmutable('2026-11-28T12:00:00Z')), $mentioning)));
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @return list<LedgerEventKind>
     */
    public function kinds(): array
    {
        return array_map(static fn (LedgerEvent $event): LedgerEventKind => $event->kind, $this->events);
    }

    /**
     * @return list<?string>
     */
    public function subjects(): array
    {
        return array_map(static fn (LedgerEvent $event): ?string => $event->subject, $this->events);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        $payloads = [];
        foreach ($this->events as $event) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
            $payloads[] = $payload;
        }

        return $payloads;
    }
}
