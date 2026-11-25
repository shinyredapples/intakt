<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\FullDisk;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Throwable;

#[Group('persistence')]
#[CoversClass(DoctrineInstallWideFailures::class)]
#[CoversClass(ItemFailureRecord::class)]
#[CoversClass(FullDisk::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(SharedHold::class)]
final class DoctrineInstallWideFailuresTest extends KernelTestCase
{
    use AssertsLoggedErrors;

    private EntityManagerInterface $entities;
    private MockClock $clock;
    private DoctrineHolds $holds;
    private DoctrineInstallWideFailures $failures;

    public function testAFailingDatabaseStartsTheFailureBackOff(): void
    {
        $this->failures->databaseFailed(new RuntimeException('connection refused'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because the database failed.');
    }

    public function testADatabaseFailureThatClosedTheEntityManagerStillStartsTheFailureBackOff(): void
    {
        // A missing table, because the persistence group runs on every engine and a read-only switch exists only in SQLite.
        $this->entities->getConnection()->executeStatement('DROP TABLE budget_item_failure');
        try {
            $this->entities->persist(new ItemFailureRecord('round-1', str_repeat('a', 64), $this->clock->now()));
            $this->entities->flush();
            self::fail('The write to a missing table succeeded.');
        } catch (TableNotFoundException $failure) {
        }
        self::assertFalse($this->entities->isOpen());

        $this->failures->databaseFailed($failure);

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because the database failed.');
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function lockStoreFailures(): iterable
    {
        yield 'a read-only store' => [new ReadOnlyException(Exception::new(new PDOException('attempt to write a readonly database')), null)];
        yield 'a lock that could not be released' => [new LockReleasingException('The lock could not be released.')];
    }

    #[DataProvider('lockStoreFailures')]
    public function testAFailingLockStoreStartsTheFailureBackOff(Throwable $failure): void
    {
        $this->failures->lockStoreFailed($failure);

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because the lock store failed.');
    }

    public function testRefusedForgeCredentialsStartTheFailureBackOff(): void
    {
        $this->failures->forgeCredentialsRefused(new RuntimeException('GitHub answered 401 to a read.'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', "The install-wide failure back-off started because the forge refused Intakt's credentials.");
    }

    public function testAFailedHandOverStartsTheFailureBackOff(): void
    {
        $this->failures->handOverFailed(new RuntimeException('the ledger is read-only'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because a hand-over failed.');
    }

    public function testTheBackOffEndsAfterItsLength(): void
    {
        $this->failures->databaseFailed(new RuntimeException('connection refused'));
        self::assertLoggedError('error', 'The install-wide failure back-off started because the database failed.');

        $this->clock->sleep(15 * 60);

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testAFullDiskReportedByTheFilesystemStartsTheFailureBackOff(): void
    {
        $this->failures->itemFailed('round-1', new IOException('Failed to write file "x": No space left on device.'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because the disk is full.');
    }

    public function testAFullDiskReportedByTheDatabaseInsideAnotherExceptionStartsTheFailureBackOff(): void
    {
        $full = new DriverException(Exception::new(new PDOException('SQLSTATE[HY000]: General error: 13 database or disk is full')), null);

        $this->failures->itemFailed('round-1', new RuntimeException('The step failed.', 0, $full));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because the disk is full.');
    }

    public function testADiskFullTextInTheMessageOfAnyOtherExceptionStartsNoHold(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('git said: No space left on device (from the pull request)'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testAnItemFailureRecordedAfterTheEntityManagerClosedStillCountsTowardsTheFailureBackOff(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));
        $this->entities->close();

        $this->failures->itemFailed('round-2', new RuntimeException('record not found'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because two items failed with the same error within an hour.');
    }

    public function testTheSameErrorOnTwoDifferentItemsWithinAnHourStartsTheFailureBackOff(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));
        $this->clock->sleep(59 * 60);
        self::assertSame([], $this->holds->sharedInEffect());

        $this->failures->itemFailed('round-2', new RuntimeException('record not found'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because two items failed with the same error within an hour.');
    }

    public function testTheSameErrorTwiceOnOneItemStartsNoHold(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testTheSameErrorOnTwoItemsMoreThanAnHourApartStartsNoHold(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));
        $this->clock->sleep(3600 + 1);

        $this->failures->itemFailed('round-2', new RuntimeException('record not found'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testDifferentErrorsOnTwoItemsStartNoHold(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));
        $this->failures->itemFailed('round-2', new RuntimeException('record is locked'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testTheSameMessageFromAnotherExceptionClassIsAnotherError(): void
    {
        $this->failures->itemFailed('round-1', new RuntimeException('record not found'));
        $this->failures->itemFailed('round-2', new LockReleasingException('record not found'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testAnItemThatFailsWhileAnotherItemsRowIsCommittedBetweenItsInsertAndItsReadPlacesTheHold(): void
    {
        $key = $this->keyOfNotFound();
        $other = $this->entityManagerOf(new EventManager());
        $events = new EventManager();
        $events->addEventListener(Events::postFlush, new OnceFlushed(function () use ($other, $key): void {
            $other->persist(new ItemFailureRecord('round-2', $key, $this->clock->now()));
            $other->flush();
        }));
        $first = $this->worker($this->entityManagerOf($events));

        $first->itemFailed('round-1', new RuntimeException('record not found'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because two items failed with the same error within an hour.');
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
        $this->clock = new MockClock('2026-11-06 08:00:00 UTC');
        $this->holds = new DoctrineHolds($entities, $this->clock);
        $this->failures = $this->worker($entities);
    }

    private function keyOfNotFound(): string
    {
        $recorded = new DoctrineInstallWideFailures($this->entities, $this->doctrine(), $this->holds, $this->clock, new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.')));
        $recorded->itemFailed('probe', new RuntimeException('record not found'));
        $key = $this->entities->getRepository(ItemFailureRecord::class)->findOneBy(['item' => 'probe'])?->errorKey;
        self::assertNotNull($key);
        $this->entities->clear();
        $this->entities->getConnection()->executeStatement('DELETE FROM budget_item_failure');

        return $key;
    }

    // Its own connection and entity manager, because another messenger:consume process shares neither with this one.
    private function entityManagerOf(EventManager $events): EntityManager
    {
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams());

        return new EntityManager($connection, $this->entities->getConfiguration(), $events);
    }

    private function worker(EntityManagerInterface $entities): DoctrineInstallWideFailures
    {
        $records = LogRecords::ofThisRun();
        self::assertNotNull($records);

        return new DoctrineInstallWideFailures($entities, $this->doctrine(), new DoctrineHolds($entities, $this->clock), $this->clock, new RecordingLogger($records));
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }
}
