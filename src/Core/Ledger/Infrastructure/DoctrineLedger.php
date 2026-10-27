<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use DateTimeZone;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use NoDiscard;
use Symfony\Component\Clock\ClockInterface;

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
        $record = new EventRecord($event->kind, $event->payload, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
        $manager = $this->registry->getManager();

        try {
            $manager->persist($record);
            $manager->flush();
        } catch (Exception $failure) {
            // A failed flush closes the entity manager for every holder, because Doctrine refuses to reopen it.
            $this->registry->resetManager();

            return AppendResult::failed('the event was not stored: '.$failure::class);
        }

        return AppendResult::appended($record->sequence);
    }

    public function readInOrder(): RecordedEvents
    {
        $records = $this->registry->getManager()->getRepository(EventRecord::class)->findBy([], ['sequence' => 'ASC']);

        return new RecordedEvents(array_map(
            static fn (EventRecord $record): RecordedEvent => new RecordedEvent($record->sequence, new LedgerEvent($record->kind(), $record->payload()), $record->recordedAt()),
            $records,
        ));
    }
}
