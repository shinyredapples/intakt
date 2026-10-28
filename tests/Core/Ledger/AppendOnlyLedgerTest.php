<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

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
        $this->storedEvent();
        $this->migrate();

        $this->expectException(DriverException::class);

        $this->manager->getConnection()->executeStatement("UPDATE ledger_event SET payload = 'changed'");
    }

    public function testDeletingAStoredEventInTheDatabaseIsRefused(): void
    {
        $this->storedEvent();
        $this->migrate();

        $this->expectException(DriverException::class);

        $this->manager->getConnection()->executeStatement('DELETE FROM ledger_event');
    }

    public function testAppendingStillWorksAfterTheDatabaseRefusesChanges(): void
    {
        $this->migrate();

        $this->storedEvent();

        self::assertSame(1, $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ledger_event'));
    }

    private function storedEvent(): EventRecord
    {
        $record = new EventRecord(new UuidV7(), LedgerEventKind::ReviewStarted, '{}', new DateTimeImmutable('2026-10-28 12:00:00'));
        $this->manager->persist($record);
        $this->manager->flush();

        return $record;
    }

    // The test schema comes from the mapping, which has no triggers, so the migration adds them.
    private function migrate(): void
    {
        // The migrations bundle loads migrations by path, so Composer's autoloader does not know this class.
        require_once dirname(__DIR__, 3).'/migrations/Version20261028120000.php';

        $connection = $this->manager->getConnection();
        $migration = new Version20261028120000($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
