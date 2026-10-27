<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Domain\EventRecord;
use Symfony\Component\Clock\ClockInterface;

final readonly class DoctrineLedger implements Ledger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        $record = new EventRecord($event->kind, $event->payload, $this->clock->now()->setTimezone(new \DateTimeZone('UTC')));

        try {
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        } catch (DbalException $failure) {
            // A failed flush leaves the manager unusable, so the next append starts clean.
            $this->entityManager->clear();

            return AppendResult::failed($failure->getMessage());
        }

        return AppendResult::appended($record->sequence());
    }

    public function readInOrder(): array
    {
        $records = $this->entityManager->getRepository(EventRecord::class)->findBy([], ['sequence' => 'ASC']);

        return array_map(
            static fn (EventRecord $record): RecordedEvent => new RecordedEvent($record->sequence(), $record->kind(), $record->payload(), $record->recordedAt()),
            $records,
        );
    }
}
