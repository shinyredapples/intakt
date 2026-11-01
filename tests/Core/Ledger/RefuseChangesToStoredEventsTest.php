<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\RefuseChangesToStoredEvents;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(RefuseChangesToStoredEvents::class)]
#[UsesClass(LedgerEventsAreAppendOnly::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class RefuseChangesToStoredEventsTest extends KernelTestCase
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
        $tool->dropDatabase();
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

    private function storedEvent(): EventRecord
    {
        $record = new EventRecord(new UuidV7(), LedgerEventKind::ReviewStarted, '{}', new DateTimeImmutable('2026-10-28 12:00:00'));
        $this->manager->persist($record);
        $this->manager->flush();

        return $record;
    }
}
