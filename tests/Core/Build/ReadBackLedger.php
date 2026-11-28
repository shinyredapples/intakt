<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

// Writes through to the run's ledger, so the push's events sit between the steps' events, and reads back what it wrote, because the pull request step finds the push it opens a pull request for in the ledger.
final class ReadBackLedger implements Ledger
{
    /** @var list<RecordedEvent> */
    private array $recorded = [];

    public function __construct(
        private readonly Ledger $ledger,
    ) {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        $result = $this->ledger->append($event);

        if ($result->succeeded()) {
            $this->recorded[] = new RecordedEvent(Uuid::v7(), $event, new MockClock('2026-11-17 10:00:00 UTC')->now());
        }

        return $result;
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents($this->recorded);
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->where(static fn (LedgerEvent $event): bool => $event->kind === $kind);
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->where(static fn (LedgerEvent $event): bool => $event->kind === $kind && $event->subject === $subject);
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->where(static fn (LedgerEvent $event): bool => $event->kind === $kind && null === $event->subject);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->where(fn (LedgerEvent $event): bool => $this->mentions($event, $texts));
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->where(fn (LedgerEvent $event): bool => in_array($event->kind, $kinds, true) && $this->mentions($event, $texts));
    }

    /**
     * @param callable(LedgerEvent): bool $matches
     */
    private function where(callable $matches): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $matches($recorded->event))));
    }

    private function mentions(LedgerEvent $event, Mentions $texts): bool
    {
        foreach ($texts as $text) {
            if (str_contains($event->payload, $text)) {
                return true;
            }
        }

        return false;
    }
}
