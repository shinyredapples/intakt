<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Closure;
use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use LogicException;
use Override;
use Symfony\Component\Uid\Uuid;

final class RecordingLedger implements Ledger
{
    /** @var list<RecordedEvent> */
    private array $recorded = [];

    public int $fullReads = 0;

    /** @var list<LedgerEventKind> */
    public array $kindsRequested = [];

    /**
     * @param ?Closure(): void $onRead
     */
    public function __construct(
        private readonly bool $down = false,
        private readonly ?Closure $onRead = null,
    ) {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if ($this->down) {
            return AppendResult::failed('the ledger is down');
        }

        $this->recorded[] = new RecordedEvent(Uuid::v7(), $event, new DateTimeImmutable('2026-11-13T12:00:00+00:00'));

        return AppendResult::appended(Uuid::v7());
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        ++$this->fullReads;

        if ($this->onRead instanceof Closure) {
            ($this->onRead)();
        }

        return new RecordedEvents($this->recorded);
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind)));
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind && $subject === $recorded->event->subject)));
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind && null === $recorded->event->subject)));
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        if ($this->onRead instanceof Closure) {
            ($this->onRead)();
        }

        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => array_any([...$texts], static fn (string $text): bool => str_contains($recorded->event->payload, $text)))));
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        $this->kindsRequested = $kinds;
        if ($this->onRead instanceof Closure) {
            ($this->onRead)();
        }

        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => in_array($recorded->event->kind, $kinds, true) && array_any([...$texts], static fn (string $text): bool => str_contains($recorded->event->payload, $text)))));
    }

    /**
     * @return list<LedgerEventKind>
     */
    public function kinds(): array
    {
        return array_map(static fn (RecordedEvent $recorded): LedgerEventKind => $recorded->event->kind, $this->recorded);
    }

    public function payloads(): string
    {
        return implode("\n", array_map(static fn (RecordedEvent $recorded): string => $recorded->event->payload, $this->recorded));
    }

    /**
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        $last = array_last($this->recorded) ?? throw new LogicException('nothing was recorded');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($last->event->payload, true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }
}
