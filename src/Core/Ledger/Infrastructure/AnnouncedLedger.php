<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventAppended;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use NoDiscard;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Uuid;

// Every door that appends goes through the ledger, so announcing here reaches every stored event without each caller dispatching its own.
#[AsDecorator(Ledger::class)]
final readonly class AnnouncedLedger implements Ledger
{
    public function __construct(
        private Ledger $inner,
        private LedgerAnnouncements $announcements,
        private ClockInterface $clock,
    ) {
    }

    // Repeated here because PHP checks the attribute on the method that runs, not on the interface.
    #[NoDiscard('a failed append is only reported through the result')]
    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        $result = $this->inner->append($event);

        if ($result->id instanceof Uuid) {
            $this->announcements->announce(new LedgerEventAppended(new RecordedEvent($result->id, $event, $this->recordedAtOf($result->id))));
        }

        return $result;
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return $this->inner->readInOrder();
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->inner->readOfKind($kind);
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->inner->readOfKindAbout($kind, $subject);
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->inner->readOfKindWithoutSubject($kind);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->inner->readMentioning($texts);
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->inner->readOfKindsMentioning($texts, ...$kinds);
    }

    // The stored id carries the moment the ledger stamped the row with, so the announced time is the one a read returns, to the millisecond.
    private function recordedAtOf(Uuid $id): DateTimeImmutable
    {
        $at = $id instanceof TimeBasedUidInterface ? $id->getDateTime() : $this->clock->now();

        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
