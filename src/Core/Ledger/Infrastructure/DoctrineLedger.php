<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Closure;
use DateTimeZone;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use LogicException;
use NoDiscard;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

// The ledger owns the default entity manager: append flushes every pending change in it and a failed append's reset discards them, so a second entity needs its own manager or a flush of only the ledger record.
final readonly class DoctrineLedger implements Ledger
{
    // SQLite refuses an expression deeper than 1000, and each text adds one LIKE to the OR chain, so a long list is read in parts.
    private const int TEXTS_PER_QUERY = 200;

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
            // A failed flush closes the entity manager for every holder, and Doctrine refuses to reopen it, so the manager is reset.
            $this->registry->resetManager();

            // The failure may not be this event's, because the flush may also have written another holder's pending change.
            return AppendResult::failed('the event was not stored: '.$failure::class);
        }

        return AppendResult::appended($record->id());
    }

    public function readInOrder(): RecordedEvents
    {
        return $this->recordedEventsOf([]);
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->recordedEventsOf(['kind' => $kind->value]);
    }

    // The index finds the rows, and the comparison here keeps only the exact subject, because a database collation may ignore case (MySQL's default does).
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->eventsOf(array_filter($this->recordsOf(['kind' => $kind->value, 'subject' => $subject]), static fn (EventRecord $record): bool => $record->subject() === $subject));
    }

    // The plain comparison lets the index find the rows, and a byte comparison beside it keeps the exact subject before the limit, because a collation that ignores case would otherwise let a newer case variant take the one row.
    public function newestOfKindAbout(LedgerEventKind $kind, string $subject): ?RecordedEvent
    {
        $manager = $this->registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        $mapping = new ResultSetMappingBuilder($manager);
        $mapping->addRootEntityFromClassMetadata(EventRecord::class, 'r');
        $platform = $manager->getConnection()->getDatabasePlatform();
        $sql = sprintf('SELECT %s FROM %s r WHERE r.kind = :kind AND r.subject = :subject AND %s ORDER BY r.position DESC', $mapping->generateSelectClause(), $manager->getClassMetadata(EventRecord::class)->getTableName(), $this->exactSubjectOn($platform));

        /** @var list<EventRecord> $newest */
        $newest = $manager->createNativeQuery($platform->modifyLimitQuery($sql, 1), $mapping)->setParameters(['kind' => $kind->value, 'subject' => $subject])->getResult();

        return array_first(iterator_to_array($this->eventsOf($newest), false));
    }

    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->recordedEventsOf(['kind' => $kind->value, 'subject' => null]);
    }

    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->eventsMentioning($texts, static fn (QueryBuilder $query): QueryBuilder => $query);
    }

    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        if ([] === $kinds) {
            return new RecordedEvents([]);
        }

        // A caller that repeats a kind must not push the query past SQLite's parameter limit: at most one bound value per case.
        $values = array_values(array_unique(array_map(static fn (LedgerEventKind $kind): string => $kind->value, $kinds)));

        return $this->eventsMentioning($texts, static fn (QueryBuilder $query): QueryBuilder => $query->andWhere('event.kind IN (:kinds)')->setParameter('kinds', $values));
    }

    // Both mention reads share the chunking and the append order, so an event named in two chunks is kept once and the reads cannot drift apart.
    /**
     * @param Closure(QueryBuilder): QueryBuilder $narrowed
     */
    private function eventsMentioning(Mentions $texts, Closure $narrowed): RecordedEvents
    {
        $records = [];
        foreach (array_chunk([...$texts], self::TEXTS_PER_QUERY) as $chunk) {
            /** @var list<EventRecord> $found */
            $found = $narrowed($this->queryMentioningAnyOf($chunk))->getQuery()->getResult();

            foreach ($found as $record) {
                $records[$record->position] = $record;
            }
        }

        ksort($records);

        return $this->eventsOf($records);
    }

    /**
     * @param list<string> $texts
     */
    private function queryMentioningAnyOf(array $texts): QueryBuilder
    {
        $manager = $this->registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        $query = $manager->createQueryBuilder()->select('event')->from(EventRecord::class, 'event');
        $mentions = [];

        foreach ($texts as $index => $text) {
            $mentions[] = "event.payload LIKE :text{$index} ESCAPE '!'";
            $query->setParameter('text'.$index, '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%');
        }

        return $query->where($query->expr()->orX(...$mentions));
    }

    /**
     * @param array<string, string|null> $criteria
     */
    private function recordedEventsOf(array $criteria): RecordedEvents
    {
        return $this->eventsOf($this->recordsOf($criteria));
    }

    /**
     * @param array<string, string|null> $criteria
     *
     * @return list<EventRecord>
     */
    private function recordsOf(array $criteria): array
    {
        return $this->registry->getManager()->getRepository(EventRecord::class)->findBy($criteria, ['position' => 'ASC']);
    }

    /**
     * @param array<EventRecord> $records
     */
    private function eventsOf(array $records): RecordedEvents
    {
        $events = [];
        foreach ($records as $record) {
            $kind = $record->kind();
            if (!$kind instanceof LedgerEventKind) {
                continue;
            }

            $events[] = new RecordedEvent($record->id(), new LedgerEvent($kind, $record->payload(), $record->subject()), $record->recordedAt());
        }

        return new RecordedEvents($events);
    }

    // Spelled per platform, because DQL has no collation and each database the install supports names its byte comparison differently; any other one throws, since a guessed comparison could ignore case.
    private function exactSubjectOn(AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof SQLitePlatform => 'r.subject = :subject COLLATE BINARY',
            $platform instanceof AbstractMySQLPlatform => 'CAST(r.subject AS BINARY) = CAST(:subject AS BINARY)',
            $platform instanceof PostgreSQLPlatform => 'r.subject = :subject COLLATE "C"',
            default => throw new LogicException(sprintf('The ledger cannot compare subjects exactly on %s.', $platform::class)),
        };
    }

    // The id takes its time from the same moment, so the id of an event carries when it was stored.
    private function recordOf(LedgerEvent $event): EventRecord
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return new EventRecord(new UuidV7(UuidV7::generate($now)), $event->kind->value, $event->payload, $now, $event->subject);
    }
}
