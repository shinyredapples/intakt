<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(DoctrineLedger::class)]
#[CoversClass(AppendResult::class)]
#[CoversClass(RecordedEvents::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class DoctrineLedgerTest extends KernelTestCase
{
    private ManagerRegistry $registry;

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->registry = $registry;

        $this->createSchema();
    }

    public function testEventsAreReadBackInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach (LedgerEventKind::cases() as $kind) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, '{"kind":"'.$kind->value.'"}'))->succeeded());
        }

        $events = iterator_to_array($ledger->readInOrder());

        self::assertSame(LedgerEventKind::cases(), array_map(static fn (RecordedEvent $event): LedgerEventKind => $event->event->kind, $events));
        self::assertSame('{"kind":"review_started"}', $events[0]->event->payload);
    }

    public function testEventsAppendedAtAnEarlierMomentAreReadFirst(): void
    {
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');
        $ledger = new DoctrineLedger($this->registry, $clock);
        $later = $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'));
        $clock->modify('-1 second');
        $earlier = $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));

        $ids = array_map(static fn (RecordedEvent $event): string => $event->id->toRfc4122(), iterator_to_array($ledger->readInOrder()));

        self::assertSame([$earlier->id?->toRfc4122(), $later->id?->toRfc4122()], $ids);
    }

    public function testTheIdOfAnEventIsAUuidV7CarryingTheMomentOfTheClock(): void
    {
        $result = $this->ledger('2026-10-27 14:00:00', '+02:00')->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));

        self::assertInstanceOf(UuidV7::class, $result->id);
        self::assertSame('2026-10-27T12:00:00+00:00', $result->id->getDateTime()->format('c'));
    }

    public function testTheMomentOfAnEventComesFromTheClockInUtc(): void
    {
        $ledger = $this->ledger('2026-10-27 14:00:00', '+02:00');
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'))->succeeded());

        $recordedAt = array_first(iterator_to_array($ledger->readInOrder()))?->recordedAt;

        self::assertSame('2026-10-27T12:00:00+00:00', $recordedAt?->format('c'));
    }

    // 02:30 on that day does not exist in Berlin, so reading the stored wall clock in the default zone would move it.
    public function testAMomentInADaylightSavingGapOfTheDefaultTimeZoneIsReadBackUnchanged(): void
    {
        $ledger = $this->ledger('2026-03-29 02:30:00', 'UTC');
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'))->succeeded());
        $this->registry->getManager()->clear();
        $defaultTimeZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $recordedAt = array_first(iterator_to_array($ledger->readInOrder()))?->recordedAt;
        } finally {
            date_default_timezone_set($defaultTimeZone);
        }

        self::assertSame('2026-03-29T02:30:00+00:00', $recordedAt?->format('c'));
    }

    public function testTheResultOfAnAppendCarriesTheIdOfTheStoredEvent(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{}'));

        self::assertTrue($result->succeeded());
        self::assertNull($result->failure);
        self::assertSame(array_first(iterator_to_array($ledger->readInOrder()))?->id->toRfc4122(), $result->id?->toRfc4122());
    }

    public function testTheResultOfAFailedAppendCarriesTheFailure(): void
    {
        $this->dropTable();

        $result = $this->ledger('2026-10-27 12:00:00', 'UTC')->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));

        self::assertFalse($result->succeeded());
        self::assertNull($result->id);
        self::assertSame('the event was not stored: Doctrine\DBAL\Exception\TableNotFoundException', $result->failure);
    }

    public function testAnAppendAfterAFailedOneIsStored(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->dropTable();
        self::assertFalse($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'))->succeeded());
        $this->createSchema();

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{}'));

        self::assertTrue($result->succeeded());
        self::assertCount(1, iterator_to_array($ledger->readInOrder()));
    }

    public function testDiscardingTheResultOfAnAppendIsReported(): void
    {
        $append = $this->ledger('2026-10-27 12:00:00', 'UTC')->append(...);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $this->discardTheResult($append, new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('a failed append is only reported through the result', $warnings[0]);
    }

    // Takes a plain Closure because static analysis refuses a discarded call it can see, and the engine still checks this one.
    private function discardTheResult(Closure $append, LedgerEvent $event): void
    {
        $append($event);
    }

    private function ledger(string $now, string $timezone): DoctrineLedger
    {
        return new DoctrineLedger($this->registry, new MockClock($now, $timezone));
    }

    private function createSchema(): void
    {
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();

        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function dropTable(): void
    {
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->getConnection()->executeStatement('DROP TABLE ledger_event');
    }
}
