<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261028120000;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\RefuseChangesToStoredEvents;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(RefuseChangesToStoredEvents::class)]
#[CoversClass(LedgerEventsAreAppendOnly::class)]
#[CoversClass(Version20261028120000::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class AppendOnlyLedgerTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    public function testUpdatingAStoredEventThroughDoctrineIsRefused(): void
    {
        $record = $this->storedEvent();
        $this->manager->getUnitOfWork()->scheduleForUpdate($record);

        $this->expectException(LedgerEventsAreAppendOnly::class);

        $this->manager->flush();
    }

    public function testDeletingAStoredEventThroughDoctrineIsRefused(): void
    {
        $this->manager->remove($this->storedEvent());

        $this->expectException(LedgerEventsAreAppendOnly::class);

        $this->manager->flush();
    }

    public function testUpdatingAStoredEventInTheDatabaseIsRefused(): void
    {
        $this->requireSqlite();

        $this->storedEvent();
        $this->migrate();

        $this->expectException(DriverException::class);

        $this->manager->getConnection()->executeStatement("UPDATE ledger_event SET payload = 'changed'");
    }

    public function testDeletingAStoredEventInTheDatabaseIsRefused(): void
    {
        $this->requireSqlite();

        $this->storedEvent();
        $this->migrate();

        $this->expectException(DriverException::class);

        $this->manager->getConnection()->executeStatement('DELETE FROM ledger_event');
    }

    public function testReplacingAStoredEventAtItsPositionInTheDatabaseIsRefused(): void
    {
        $this->requireSqlite();

        $record = $this->storedEvent();
        $this->migrate();

        $this->expectException(DriverException::class);

        $this->manager->getConnection()->executeStatement(
            "INSERT OR REPLACE INTO ledger_event (position, id, kind, payload, recorded_at) VALUES (?, ?, 'judge_done', 'changed', '2026-10-28 12:00:00')",
            [$record->position, new UuidV7()->toRfc4122()],
        );
    }

    public function testReplacingAStoredEventByItsIdInTheDatabaseIsRefused(): void
    {
        $this->requireSqlite();

        $this->storedEvent();
        $this->migrate();
        $connection = $this->manager->getConnection();
        $storedId = $connection->fetchOne('SELECT id FROM ledger_event');

        $this->expectException(DriverException::class);

        $connection->executeStatement(
            "INSERT OR REPLACE INTO ledger_event (id, kind, payload, recorded_at) VALUES (?, 'judge_done', 'changed', '2026-10-28 12:00:00')",
            [$storedId],
        );
    }

    public function testAppendingStillWorksAfterTheDatabaseRefusesChanges(): void
    {
        $this->migrate();

        $this->storedEvent();

        self::assertSame(1, $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_event'));
    }

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropDatabase();
        $tool->createSchema($metadata);
    }

    // Only SQLite has the triggers and INSERT OR REPLACE, because the migration adds them nowhere else; other engines rely on the Doctrine listener.
    private function requireSqlite(): void
    {
        if (!$this->manager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The database refuses changes through SQLite triggers only.');
        }
    }

    private function storedEvent(): EventRecord
    {
        $record = new EventRecord(new UuidV7(), LedgerEventKind::ReviewStarted->value, '{}', new DateTimeImmutable('2026-10-28 12:00:00'));
        $this->manager->persist($record);
        $this->manager->flush();

        return $record;
    }

    // The test schema comes from the mapping, which has no triggers, so the migration adds them.
    private function migrate(): void
    {
        $connection = $this->manager->getConnection();
        $migration = new Version20261028120000($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
