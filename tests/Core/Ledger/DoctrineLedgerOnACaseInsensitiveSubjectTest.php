<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

// An SQLite column with NOCASE, because it ignores case as MySQL's default collation does, and the suite cannot rely on a MySQL server.
#[Group('persistence')]
#[CoversClass(DoctrineLedger::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class DoctrineLedgerOnACaseInsensitiveSubjectTest extends KernelTestCase
{
    private DoctrineLedger $ledger;

    public function testAReadAboutASubjectThatDiffersOnlyInCaseReadsNothing(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, 'first', 'r1'))->succeeded());

        self::assertSame([], iterator_to_array($this->ledger->readOfKindAbout(LedgerEventKind::ReviewerStarted, 'R1')));
    }

    public function testAReadAboutTheExactSubjectStillReadsItsEvent(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, 'first', 'r1'))->succeeded());

        self::assertSame(['first'], array_map(static fn (RecordedEvent $event): string => $event->event->payload, iterator_to_array($this->ledger->readOfKindAbout(LedgerEventKind::ReviewerStarted, 'r1'))));
    }

    public function testTheNewestEventAboutASubjectThatDiffersOnlyInCaseIsNone(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, 'first', 'r1'))->succeeded());

        self::assertNull($this->ledger->newestOfKindAbout(LedgerEventKind::AccountPicked, 'R1'));
    }

    public function testTheNewestEventAboutTheExactSubjectSkipsALaterOneThatDiffersOnlyInCase(): void
    {
        foreach (['first' => 'r1', 'second' => 'r1', 'other case' => 'R1'] as $payload => $subject) {
            self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::AccountPicked, $payload, $subject))->succeeded());
        }

        self::assertSame('second', $this->ledger->newestOfKindAbout(LedgerEventKind::AccountPicked, 'r1')?->event->payload);
    }

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ledger_event (position INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, id CHAR(36) NOT NULL, kind VARCHAR(32) NOT NULL, payload CLOB NOT NULL, recorded_at DATETIME NOT NULL, subject VARCHAR(255) DEFAULT NULL COLLATE NOCASE)');
        $connection->executeStatement('CREATE INDEX ledger_event_subject ON ledger_event (kind, subject, position)');

        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->managerOn($connection));
        $this->ledger = new DoctrineLedger($registry, new MockClock('2026-11-22 12:00:00'));
    }

    private function managerOn(Connection $connection): EntityManagerInterface
    {
        $original = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $original);
        $defaultManager = $original->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $defaultManager);

        return new EntityManager($connection, $defaultManager->getConfiguration());
    }
}
