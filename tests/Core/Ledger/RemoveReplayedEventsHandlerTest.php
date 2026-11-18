<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Contract\RemoveReplayedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\RefuseChangesToStoredEvents;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Core\Ledger\UseCase\RemoveReplayedEvents\RemoveReplayedEventsHandler;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(RemoveReplayedEventsHandler::class)]
#[CoversClass(RemoveReplayedEvents::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(LedgerEventsAreAppendOnly::class)]
#[UsesClass(RefuseChangesToStoredEvents::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class RemoveReplayedEventsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    public function testOnlyTheNamedEventsAreRemoved(): void
    {
        $replayed = $this->storedEvent('{"round":"replayed"}');
        $this->storedEvent('{"round":"live"}');
        $alsoReplayed = $this->storedEvent('{"call":"replayed-call"}');

        $this->remove($replayed, $alsoReplayed);

        self::assertSame(['{"round":"live"}'], $this->storedPayloads());
    }

    public function testAnIdTheLedgerDoesNotHoldDoesNotStopTheKnownRowFromGoing(): void
    {
        $this->requireSqlite();
        $replayed = $this->storedEvent('{"round":"replayed"}');
        $this->storedEvent('{"round":"live"}');

        $this->remove(new UuidV7(), $replayed);

        self::assertSame(['{"round":"live"}'], $this->storedPayloads());
        $this->expectException(DriverException::class);
        $this->manager->getConnection()->executeStatement('DELETE FROM ledger_event');
    }

    public function testRemovingNoEventsKeepsEveryRow(): void
    {
        $this->storedEvent('{"round":"live"}');

        $this->remove();

        self::assertSame(['{"round":"live"}'], $this->storedPayloads());
    }

    public function testTheDatabaseStillRefusesADeleteAfterARemoval(): void
    {
        $this->requireSqlite();
        $this->remove($this->storedEvent('{"round":"replayed"}'));
        $this->storedEvent('{"round":"live"}');

        $this->expectException(DriverException::class);

        $this->manager->getConnection()->executeStatement('DELETE FROM ledger_event');
    }

    public function testADeleteThroughDoctrineIsStillRefusedAfterARemoval(): void
    {
        $this->remove($this->storedEvent('{"round":"replayed"}'));
        $record = new EventRecord(new UuidV7(), LedgerEventKind::ReviewStarted->value, '{"round":"live"}', new DateTimeImmutable('2026-11-17 12:00:00'));
        $this->manager->persist($record);
        $this->manager->flush();
        $this->manager->remove($record);

        $this->expectException(LedgerEventsAreAppendOnly::class);

        $this->manager->flush();
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        AppendOnlyTriggers::in($manager->getConnection());
    }

    private function storedEvent(string $payload): Uuid
    {
        $id = new UuidV7();
        $this->manager->persist(new EventRecord($id, LedgerEventKind::ReviewStarted->value, $payload, new DateTimeImmutable('2026-11-17 12:00:00')));
        $this->manager->flush();

        return $id;
    }

    private function remove(Uuid ...$events): void
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);
        $commands->dispatch(new RemoveReplayedEvents(array_values($events)));
    }

    /**
     * @return list<string>
     */
    private function storedPayloads(): array
    {
        return array_values(array_filter($this->manager->getConnection()->fetchFirstColumn('SELECT payload FROM ledger_event ORDER BY position'), is_string(...)));
    }

    // Only SQLite has the triggers, because the migration adds them nowhere else; other engines rely on the Doctrine listener.
    private function requireSqlite(): void
    {
        if (!$this->manager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The database refuses deletes through SQLite triggers only.');
        }
    }
}
