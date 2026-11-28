<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventAppended;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\AnnounceAfterCommit;
use Intakt\Core\Ledger\Infrastructure\AnnouncedLedger;
use Intakt\Core\Ledger\Infrastructure\AnnouncingConnection;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\LedgerAnnouncements;
use Intakt\Core\Ledger\Infrastructure\RefuseChangesToStoredEvents;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AnnouncedLedger::class)]
#[CoversClass(LedgerAnnouncements::class)]
#[CoversClass(AnnounceAfterCommit::class)]
#[CoversClass(AnnouncingConnection::class)]
#[CoversClass(LedgerEventAppended::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(RefuseChangesToStoredEvents::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class AnnouncedLedgerTest extends KernelTestCase
{
    /**
     * @var list<RecordedEvent>
     */
    private array $announced = [];

    private Connection $connection;

    private EntityManager $manager;

    private Ledger $ledger;

    public function testAnEventAppendedOutsideATransactionIsAnnouncedAtOnceWithItsStoredIdAndTime(): void
    {
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerAsked, '{"actor":"owner"}'));

        self::assertCount(1, $this->announced);
        self::assertSame($result->id?->toRfc4122(), $this->announced[0]->id->toRfc4122());
        self::assertSame('{"actor":"owner"}', $this->announced[0]->event->payload);
        $stored = iterator_to_array($this->ledger->readInOrder())[0];
        self::assertSame($stored->recordedAt->format('Y-m-d H:i:s.v'), $this->announced[0]->recordedAt->format('Y-m-d H:i:s.v'));
    }

    public function testAnEventAppendedInsideATransactionIsAnnouncedOnlyOnceTheTransactionCommits(): void
    {
        $this->connection->transactional(function (): void {
            $this->appendOne();
            self::assertSame([], $this->announced);
        });

        self::assertCount(1, $this->announced);
    }

    public function testAnEventAppendedInsideANestedTransactionWaitsForTheOutermostCommit(): void
    {
        $this->connection->transactional(function (): void {
            $this->connection->transactional(fn () => $this->appendOne());
            self::assertSame([], $this->announced);
        });

        self::assertCount(1, $this->announced);
    }

    public function testAnEventWhoseTransactionRollsBackIsNeverAnnounced(): void
    {
        try {
            $this->connection->transactional(function (): void {
                $this->appendOne();

                throw new LogicException('the work failed');
            });
        } catch (LogicException) {
            // As the ledger's transactional callers do, because the rolled-back record stays in the identity map.
            $this->manager->clear();
        }

        $this->appendOne();

        self::assertCount(1, $this->announced);
        self::assertCount(1, iterator_to_array($this->ledger->readInOrder()));
    }

    public function testACommitTheDatabaseRefusesAnnouncesNothingItHeldAndLeavesTheTransactionClosed(): void
    {
        $driverConnection = self::createStub(DriverConnection::class);
        $driverConnection->method('commit')->willThrowException(new class('the disk is full') extends AbstractException {});
        $announcements = $this->announcements();
        $connection = new AnnouncingConnection($driverConnection, $announcements);
        $connection->beginTransaction();
        $announcements->announce($this->appended());

        try {
            $connection->commit();
            self::fail('The refused commit was not reported.');
        } catch (AbstractException) {
        }

        self::assertSame([], $this->announced);
        $announcements->announce($this->appended());
        self::assertCount(1, $this->announced);
    }

    public function testAFailedAppendAnnouncesNothing(): void
    {
        $refusing = self::createStub(Ledger::class);
        $refusing->method('append')->willReturn(AppendResult::failed('disk full'));
        $ledger = new AnnouncedLedger($refusing, $this->announcements(), new MockClock());

        self::assertFalse($ledger->append(new LedgerEvent(LedgerEventKind::OwnerAsked, '{}'))->succeeded());

        self::assertSame([], $this->announced);
    }

    public function testAnIdWithoutATimeIsAnnouncedAtTheClocksMoment(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturn(AppendResult::appended(Uuid::v4()));

        $result = new AnnouncedLedger($ledger, $this->announcements(), new MockClock('2026-11-28 10:00:00', 'Europe/Berlin'))->append(new LedgerEvent(LedgerEventKind::OwnerAsked, '{}'));

        self::assertTrue($result->succeeded());
        self::assertSame('2026-11-28T09:00:00+00:00', $this->announced[0]->recordedAt->format(DATE_ATOM));
    }

    public function testTheApplicationLedgerAnnouncesAndItsDefaultConnectionHoldsAnnouncementsUntilCommit(): void
    {
        $container = self::getContainer();

        self::assertInstanceOf(AnnouncedLedger::class, $container->get(Ledger::class));
        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertNotSame([], array_filter($connection->getConfiguration()->getMiddlewares(), static fn (object $middleware): bool => $middleware instanceof AnnounceAfterCommit));
    }

    protected function setUp(): void
    {
        $announcements = $this->announcements();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new AnnounceAfterCommit($announcements)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $original = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $original);
        $defaultManager = $original->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $defaultManager);
        $events = $defaultManager->getEventManager();
        self::assertInstanceOf(EventManager::class, $events);
        $this->manager = new EntityManager($this->connection, $defaultManager->getConfiguration(), $events);
        new SchemaTool($this->manager)->createSchema($this->manager->getMetadataFactory()->getAllMetadata());

        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->manager);
        $this->ledger = new AnnouncedLedger(new DoctrineLedger($registry, new MockClock('2026-11-28 12:00:00')), $announcements, new MockClock());
    }

    private function announcements(): LedgerAnnouncements
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LedgerEventAppended::class, function (LedgerEventAppended $appended): void {
            $this->announced[] = $appended->recorded;
        });

        return new LedgerAnnouncements($dispatcher);
    }

    private function appended(): LedgerEventAppended
    {
        return new LedgerEventAppended(new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::OwnerAsked, '{}'), new DateTimeImmutable('2026-11-28 12:00:00')));
    }

    private function appendOne(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerAsked, '{}'))->succeeded());
    }
}
