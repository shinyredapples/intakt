<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use DateTimeZone;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use NoDiscard;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

// The ledger owns the default entity manager: append flushes every pending change in it and a failed append's reset discards them, so a second entity needs its own manager or a flush of only the ledger record.
final readonly class DoctrineLedger implements Ledger
{
    public function __construct(
        private ManagerRegistry $registry,
        private ClockInterface $clock,
    ) {
    }

    // Repeated here because PHP checks the attribute on the method that runs, not on the interface.
    #[NoDiscard('a failed append is only reported through the result')]
    public function append(LedgerEvent $event): AppendResult
    {
        $record = $this->recordOf($event);
        $manager = $this->registry->getManager();

        try {
            $manager->persist($record);
            $manager->flush();
        } catch (Exception|EntityManagerClosed|LedgerEventsAreAppendOnly $failure) {
            // A failed flush closes the entity manager for every holder, because Doctrine refuses to reopen it; a refusal here belongs to another holder's pending change, not to this append.
            $this->registry->resetManager();

            return AppendResult::failed('the event was not stored: '.$failure::class);
        }

        return AppendResult::appended($record->id());
    }

    public function readInOrder(): RecordedEvents
    {
        $records = $this->registry->getManager()->getRepository(EventRecord::class)->findBy([], ['position' => 'ASC']);

        return new RecordedEvents(array_map(
            static fn (EventRecord $record): RecordedEvent => new RecordedEvent($record->id(), new LedgerEvent($record->kind(), $record->payload()), $record->recordedAt()),
            $records,
        ));
    }

    // The id takes its time from the same moment, so the id of an event carries when it was stored.
    private function recordOf(LedgerEvent $event): EventRecord
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return new EventRecord(new UuidV7(UuidV7::generate($now)), $event->kind, $event->payload, $now);
    }
}
