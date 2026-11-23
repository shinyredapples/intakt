<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\RefuseChangesToStoredEvents;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(DoctrineLedger::class)]
#[CoversClass(AppendResult::class)]
#[CoversClass(RecordedEvents::class)]
#[CoversClass(RefuseChangesToStoredEvents::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(LedgerEventsAreAppendOnly::class)]
#[UsesClass(Mentions::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class DoctrineLedgerTest extends KernelTestCase
{
    private ManagerRegistry $registry;

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

    public function testAReadAboutASubjectReturnsOnlyTheEventsOfThatKindAndExactSubjectInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach ([[LedgerEventKind::ReviewerStarted, 'first', 'r1'], [LedgerEventKind::ReviewerStarted, 'other round', 'r10'], [LedgerEventKind::VerdictReceived, 'other kind', 'r1'], [LedgerEventKind::ReviewerStarted, 'no subject', null], [LedgerEventKind::ReviewerStarted, 'retry', 'r1']] as [$kind, $payload, $subject]) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, $payload, $subject))->succeeded());
        }

        $events = iterator_to_array($ledger->readOfKindAbout(LedgerEventKind::ReviewerStarted, 'r1'));

        self::assertSame(['first', 'retry'], array_map(static fn (RecordedEvent $event): string => $event->event->payload, $events));
        self::assertSame(['r1', 'r1'], array_map(static fn (RecordedEvent $event): ?string => $event->event->subject, $events));
    }

    public function testAReadAboutAPrefixOfAStoredSubjectReadsNothing(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, 'first', 'r1'))->succeeded());

        self::assertSame([], iterator_to_array($ledger->readOfKindAbout(LedgerEventKind::ReviewerStarted, 'r')));
    }

    public function testAReadWithoutSubjectReturnsOnlyTheEventsOfThatKindAppendedWithoutOneInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach ([[LedgerEventKind::ReviewerStarted, 'first', null], [LedgerEventKind::ReviewerStarted, 'with subject', 'r1'], [LedgerEventKind::VerdictReceived, 'other kind', null], [LedgerEventKind::ReviewerStarted, 'second', null]] as [$kind, $payload, $subject]) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, $payload, $subject))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readOfKindWithoutSubject(LedgerEventKind::ReviewerStarted)));

        self::assertSame(['first', 'second'], $payloads);
    }

    public function testAReadOfOneKindReturnsOnlyTheEventsOfThatKindInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach ([[LedgerEventKind::VerdictReceived, 'first'], [LedgerEventKind::JudgeDone, 'other'], [LedgerEventKind::VerdictReceived, 'second']] as [$kind, $payload]) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, $payload))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readOfKind(LedgerEventKind::VerdictReceived)));

        self::assertSame(['first', 'second'], $payloads);
    }

    public function testAReadByTextReturnsTheEventsWhosePayloadNamesAnyOfTheTextsInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach ([[LedgerEventKind::ReviewStarted, '{"round":"r-2"}'], [LedgerEventKind::JudgeDone, '{"round":"r-1"}'], [LedgerEventKind::VerdictReceived, '{"round":"r-3"}'], [LedgerEventKind::UsageRecorded, '{"call":"c-1","round":"r-2"}']] as [$kind, $payload]) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, $payload))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readMentioning(new Mentions('r-2', 'r-1'))));

        self::assertSame(['{"round":"r-2"}', '{"round":"r-1"}', '{"call":"c-1","round":"r-2"}'], $payloads);
    }

    public function testAReadByTextTakesItsWildcardAndEscapeCharactersLiterally(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach (['{"note":"a_b"}', '{"note":"axb"}', '{"note":"50%!"}', '{"note":"50 and more"}'] as $payload) {
            self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, $payload))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readMentioning(new Mentions('a_b', '50%!'))));

        self::assertSame(['{"note":"a_b"}', '{"note":"50%!"}'], $payloads);
    }

    public function testAReadByMoreTextsThanOneQueryHoldsReturnsEachMatchingEventOnceInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $calls = array_map(static fn (int $n): string => sprintf('00000000-0000-7000-8000-%012d', $n), range(1, 1100));

        foreach (['{"call":"'.$calls[1099].'"}', '{"call":"none"}', '{"call":"'.$calls[0].'","also":"'.$calls[1099].'"}', '{"call":"'.$calls[500].'"}'] as $payload) {
            self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, $payload))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readMentioning(new Mentions(...$calls))));

        self::assertSame(['{"call":"'.$calls[1099].'"}', '{"call":"'.$calls[0].'","also":"'.$calls[1099].'"}', '{"call":"'.$calls[500].'"}'], $payloads);
    }

    public function testAReadByNoTextReadsNothing(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"r-1"}'))->succeeded());

        self::assertSame([], iterator_to_array($ledger->readMentioning(new Mentions())));
    }

    public function testAReadByKindsAndTextsReturnsOnlyEventsOfThoseKindsThatMentionATextInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach ([[LedgerEventKind::JudgeDone, '{"round":"r-1"}'], [LedgerEventKind::ReviewStarted, '{"round":"r-1"}'], [LedgerEventKind::UsageRecorded, '{"round":"r-1"}'], [LedgerEventKind::JudgeDone, '{"round":"r-2"}'], [LedgerEventKind::ReviewStarted, '{"round":"r-3"}']] as [$kind, $payload]) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, $payload))->succeeded());
        }

        $events = $ledger->readOfKindsMentioning(new Mentions('r-2', 'r-1'), LedgerEventKind::ReviewStarted, LedgerEventKind::JudgeDone);

        self::assertSame([[LedgerEventKind::JudgeDone, '{"round":"r-1"}'], [LedgerEventKind::ReviewStarted, '{"round":"r-1"}'], [LedgerEventKind::JudgeDone, '{"round":"r-2"}']], array_map(static fn (RecordedEvent $event): array => [$event->event->kind, $event->event->payload], iterator_to_array($events)));
    }

    public function testAReadByKindsAndTextsTakesItsWildcardAndEscapeCharactersLiterally(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');

        foreach (['{"note":"a_b"}', '{"note":"axb"}', '{"note":"50%!"}'] as $payload) {
            self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, $payload))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readOfKindsMentioning(new Mentions('a_b', '50%!'), LedgerEventKind::JudgeDone)));

        self::assertSame(['{"note":"a_b"}', '{"note":"50%!"}'], $payloads);
    }

    public function testAReadByKindsAndMoreTextsThanOneQueryHoldsReturnsEachMatchingEventOnceInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $calls = array_map(static fn (int $n): string => sprintf('00000000-0000-7000-8000-%012d', $n), range(1, 1100));

        foreach ([[LedgerEventKind::UsageRecorded, '{"call":"'.$calls[1099].'"}'], [LedgerEventKind::JudgeDone, '{"call":"'.$calls[0].'"}'], [LedgerEventKind::UsageRecorded, '{"call":"'.$calls[0].'","also":"'.$calls[1099].'"}'], [LedgerEventKind::UsageRecorded, '{"call":"'.$calls[500].'"}']] as [$kind, $payload]) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, $payload))->succeeded());
        }

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readOfKindsMentioning(new Mentions(...$calls), LedgerEventKind::UsageRecorded)));

        self::assertSame(['{"call":"'.$calls[1099].'"}', '{"call":"'.$calls[0].'","also":"'.$calls[1099].'"}', '{"call":"'.$calls[500].'"}'], $payloads);
    }

    public function testAReadByOneKindRepeatedAThousandTimesReturnsTheMatchingEventOnce(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"r-1"}'))->succeeded());
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"r-1"}'))->succeeded());

        $payloads = array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($ledger->readOfKindsMentioning(new Mentions('r-1'), ...array_fill(0, 1000, LedgerEventKind::JudgeDone))));

        self::assertSame(['{"round":"r-1"}'], $payloads);
    }

    // Counted in the bound values, because the engine's own parameter limit differs between builds and may admit the repeated kinds.
    public function testAReadByOneKindRepeatedBindsTheKindOnce(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $ledger->readOfKindsMentioning(new Mentions('r-1'), LedgerEventKind::JudgeDone, LedgerEventKind::JudgeDone, LedgerEventKind::JudgeDone);

        $bound = array_merge(...array_map(static fn (array $query): array => array_values((array) $query['params']), array_merge(...array_values($queries->getData()))));
        self::assertSame(1, count(array_keys($bound, LedgerEventKind::JudgeDone->value, true)));
    }

    public function testAReadByNoKindOrNoTextReadsNothing(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"r-1"}'))->succeeded());

        self::assertSame([], iterator_to_array($ledger->readOfKindsMentioning(new Mentions('r-1'))));
        self::assertSame([], iterator_to_array($ledger->readOfKindsMentioning(new Mentions(), LedgerEventKind::JudgeDone)));
    }

    public function testEventsAreReadInAppendOrderAfterTheClockStepsBack(): void
    {
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');
        $ledger = new DoctrineLedger($this->registry, $clock);
        $first = $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));
        $clock->modify('-1 second');
        $second = $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'));

        $ids = array_map(static fn (RecordedEvent $event): string => $event->id->toRfc4122(), iterator_to_array($ledger->readInOrder()));

        self::assertSame([$first->id?->toRfc4122(), $second->id?->toRfc4122()], $ids);
    }

    // Stores the records directly to force the order two processes in one millisecond can produce, because their ids sort either way.
    public function testAnEventStoredLaterIsReadLaterEvenWhenItsIdSortsLower(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $manager = $this->registry->getManager();
        $manager->persist(new EventRecord(UuidV7::fromString('019a2600-0000-7fff-bfff-ffffffffffff'), LedgerEventKind::ReviewStarted->value, '{}', new DateTimeImmutable('2026-10-27 12:00:00')));
        $manager->persist(new EventRecord(UuidV7::fromString('019a2600-0000-7000-8000-000000000000'), LedgerEventKind::JudgeDone->value, '{}', new DateTimeImmutable('2026-10-27 12:00:00')));
        $manager->flush();
        $manager->clear();

        $kinds = array_map(static fn (RecordedEvent $event): LedgerEventKind => $event->event->kind, iterator_to_array($ledger->readInOrder()));

        self::assertSame([LedgerEventKind::ReviewStarted, LedgerEventKind::JudgeDone], $kinds);
    }

    public function testAStoredEventOfAKindThisCodeNoLongerKnowsIsSkippedAndTheOthersAreStillRead(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $manager = $this->registry->getManager();
        $manager->persist(new EventRecord(new UuidV7(), 'shadow_round_held', '{}', new DateTimeImmutable('2026-10-27 12:00:00')));
        $manager->persist(new EventRecord(new UuidV7(), LedgerEventKind::JudgeDone->value, '{}', new DateTimeImmutable('2026-10-27 12:00:00')));
        $manager->flush();
        $manager->clear();

        $kinds = array_map(static fn (RecordedEvent $event): LedgerEventKind => $event->event->kind, iterator_to_array($ledger->readInOrder()));

        self::assertSame([LedgerEventKind::JudgeDone], $kinds);
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

    public function testAnAppendToAManagerThatAnotherFailureClosedReportsTheFailure(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->closeTheManager();

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));

        self::assertFalse($result->succeeded());
        self::assertSame('the event was not stored: Doctrine\ORM\Exception\EntityManagerClosed', $result->failure);
    }

    public function testAnAppendAfterOneToAClosedManagerIsStored(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->closeTheManager();
        self::assertFalse($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'))->succeeded());

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{}'));

        self::assertTrue($result->succeeded());
        self::assertCount(1, iterator_to_array($ledger->readInOrder()));
    }

    public function testAnAppendAfterARefusedUpdateIsStored(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $manager = $this->entityManager();
        $manager->getUnitOfWork()->scheduleForUpdate($this->storedEvent());
        $this->assertTheFlushIsRefused();

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'));

        self::assertTrue($result->succeeded());
        self::assertCount(2, iterator_to_array($ledger->readInOrder()));
    }

    public function testAnAppendAfterARefusedDeleteIsStored(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->entityManager()->remove($this->storedEvent());
        $this->assertTheFlushIsRefused();

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'));

        self::assertTrue($result->succeeded());
        self::assertCount(2, iterator_to_array($ledger->readInOrder()));
    }

    public function testAnAppendWhileARefusedDeleteIsPendingReportsTheRefusal(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->entityManager()->remove($this->storedEvent());

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'));

        self::assertFalse($result->succeeded());
        self::assertSame('the event was not stored: Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly', $result->failure);
        self::assertCount(1, iterator_to_array($ledger->readInOrder()));
    }

    public function testAnAppendWhileARefusedUpdateIsPendingReportsTheRefusal(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->entityManager()->getUnitOfWork()->scheduleForUpdate($this->storedEvent());

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'));

        self::assertFalse($result->succeeded());
        self::assertSame('the event was not stored: Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly', $result->failure);
    }

    public function testAnAppendAfterOneThatMetARefusedChangeIsStored(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00', 'UTC');
        $this->entityManager()->remove($this->storedEvent());
        self::assertFalse($ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{}'))->succeeded());

        $result = $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{}'));

        self::assertTrue($result->succeeded());
        self::assertCount(2, iterator_to_array($ledger->readInOrder()));
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

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->registry = $registry;

        $this->createSchema();
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
        $tool->dropDatabase();
        $tool->createSchema($metadata);
    }

    private function closeTheManager(): void
    {
        $this->entityManager()->close();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function storedEvent(): EventRecord
    {
        $record = new EventRecord(new UuidV7(), LedgerEventKind::ReviewStarted->value, '{}', new DateTimeImmutable('2026-10-27 12:00:00'));
        $this->entityManager()->persist($record);
        $this->entityManager()->flush();

        return $record;
    }

    private function assertTheFlushIsRefused(): void
    {
        try {
            $this->entityManager()->flush();
        } catch (LedgerEventsAreAppendOnly) {
            return;
        }

        self::fail('the flush was not refused');
    }

    private function dropTable(): void
    {
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->getConnection()->executeStatement('DROP TABLE ledger_event');
    }
}
