<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(DoctrineLedger::class)]
#[CoversClass(AppendResult::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(RecordedEvent::class)]
final class DoctrineLedgerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;

        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
    }

    public function testEventsAreReadBackInTheOrderTheyWereAppended(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00');

        foreach (LedgerEventKind::cases() as $kind) {
            self::assertTrue($ledger->append(new LedgerEvent($kind, '{"kind":"'.$kind->value.'"}'))->succeeded());
        }

        $events = $ledger->readInOrder();

        self::assertSame(LedgerEventKind::cases(), array_map(static fn (RecordedEvent $event): LedgerEventKind => $event->kind, $events));
        self::assertSame('{"kind":"review_started"}', $events[0]->payload);
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (RecordedEvent $event): int => $event->sequence, $events));
    }

    public function testTheMomentOfAnEventComesFromTheClockInUtc(): void
    {
        $ledger = $this->ledger('2026-10-27 12:00:00');
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));

        $recordedAt = $ledger->readInOrder()[0]->recordedAt;

        self::assertSame('2026-10-27T12:00:00+00:00', $recordedAt->format('c'));
    }

    public function testTheResultOfAnAppendCarriesTheSequence(): void
    {
        $result = $this->ledger('2026-10-27 12:00:00')->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{}'));

        self::assertTrue($result->succeeded());
        self::assertSame(1, $result->sequence);
        self::assertNull($result->failure);
    }

    public function testTheResultOfAFailedAppendCarriesTheFailure(): void
    {
        $this->entityManager->getConnection()->executeStatement('DROP TABLE ledger_event');

        $result = $this->ledger('2026-10-27 12:00:00')->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{}'));

        self::assertFalse($result->succeeded());
        self::assertNull($result->sequence);
        self::assertStringContainsString('ledger_event', (string) $result->failure);
    }

    private function ledger(string $now): DoctrineLedger
    {
        return new DoctrineLedger($this->entityManager, new MockClock($now, 'UTC'));
    }
}
