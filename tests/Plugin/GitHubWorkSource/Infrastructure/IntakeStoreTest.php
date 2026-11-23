<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Infrastructure;

use DateTimeZone;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubWorkSource\CommitOnValidatorLoad;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\GitHubWorkSource\WriterBetweenWriteAndCommit;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use WeakReference;

#[Group('persistence')]
#[CoversClass(IntakeStore::class)]
#[CoversClass(StoredIssue::class)]
#[CoversClass(IntakeValidator::class)]
#[CoversClass(TaskFailure::class)]
#[CoversClass(Vouching::class)]
#[CoversClass(ListingRows::class)]
#[CoversClass(SyncState::class)]
#[CoversClass(ProjectHeldByAnotherWriter::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(TaskNotQueued::class)]
final class IntakeStoreTest extends KernelTestCase
{
    private const array WRITERS = ['ana'];

    private EntityManagerInterface $entities;
    private IntakeStore $store;

    public function testAStoredIssueIsReadBackAsTheItemItWasStoredFrom(): void
    {
        $listed = Issues::task(7, ['intakt', 'priority:high'], ['body' => "Waits for #3\nWaits for whatever", 'due' => '2026-12-01T09:30:00Z', 'updatedAt' => '2026-11-10T10:00:00Z']);
        $this->record('alpha', [$this->vouched($listed)], null);
        $this->entities->clear();

        $queued = array_first($this->store->issuesOf('alpha'))?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertSame(7, $queued->number());
        self::assertFalse($queued->isPullRequest());
        self::assertTrue($queued->isTrusted());
        self::assertTrue($queued->hasVouchedLabel('intakt'));
        self::assertSame('high', $queued->priority()->value);
        self::assertSame([3], $queued->waitsFor());
        self::assertSame(['Waits for whatever'], $queued->unreadableWaits());
        self::assertSame('2026-12-01 09:30:00', $queued->milestoneDue()?->format('Y-m-d H:i:s'));
    }

    public function testAnItemWhoseHistoryWasNotReadIsReadBackUntrusted(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], null);

        $queued = array_first($this->store->issuesOf('alpha'))?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertFalse($queued->isTrusted());
        self::assertFalse($queued->hasVouchedLabel('intakt'));
    }

    public function testAnItemVouchedForByAnAccountWithoutWriteAccessIsReadBackUntrustedWithNoVouchedLabel(): void
    {
        $issue = $this->stored(Issues::task(7, ['intakt']));
        $issue->vouch(Vouching::of('mallory', new IssueHistory('mallory', [new LabelApplication('intakt', 'mallory')], '', ['intakt']), static fn (string $account): bool => in_array($account, self::WRITERS, true)));
        $this->record('alpha', [$issue], null);
        $this->entities->clear();

        $stored = array_first($this->store->issuesOf('alpha'));
        $queued = $stored?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertTrue($stored?->isVouched());
        self::assertFalse($queued->isTrusted());
        self::assertFalse($queued->hasVouchedLabel('intakt'));
    }

    public function testAPullRequestIsReadBackWithTheTasksItCloses(): void
    {
        $this->record('alpha', [$this->stored(Issues::pullRequest(12, 'Closes #7'))], null);

        $queued = array_first($this->store->issuesOf('alpha'))?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertTrue($queued->isPullRequest());
        self::assertSame([7], $queued->closedBy());
    }

    public function testTheUpdateTimeIsStoredInUtcWithItsFractionOfASecond(): void
    {
        $listed = Issues::task(7);
        $later = new ListedIssue(7, $listed->title, '', $listed->labels, $listed->author, $listed->updatedAt->setTimezone(new DateTimeZone('Europe/Berlin'))->modify('+250 milliseconds'), null, false);

        $this->record('alpha', [$this->stored($later)], null);

        self::assertSame('2026-11-10 10:00:00.250000', $this->entities->getConnection()->fetchOne('SELECT updated_at FROM work_source_issue WHERE number = 7'));
    }

    public function testAnIssueRecordedAgainReplacesItsRowInsteadOfAddingOne(): void
    {
        $this->record('alpha', [$this->vouched(Issues::task(7, ['intakt'], ['body' => 'Waits for #3']))], null);

        $this->record('alpha', [$this->stored(Issues::task(7, ['intakt'], ['body' => 'Waits for #4']))], null);

        $issues = $this->store->issuesOf('alpha');
        self::assertCount(1, $issues);
        self::assertSame([4], $issues[0]->queued()->waitsFor());
        self::assertFalse($issues[0]->queued()->isTrusted());
    }

    public function testARowReadBackAndRecordedAgainKeepsWhatItsHistorySaid(): void
    {
        $this->record('alpha', [$this->vouched(Issues::task(7))], null);

        $this->record('alpha', $this->store->issuesOf('alpha'), null);

        self::assertTrue(array_first($this->store->issuesOf('alpha'))?->queued()->isTrusted());
    }

    public function testAnIssueTheListingNoLongerShowsLosesItsRow(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7)), $this->stored(Issues::task(8))], null);

        $this->record('alpha', [$this->stored(Issues::task(8))], null);

        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testTheRowsOfAnotherProjectAreLeftAlone(): void
    {
        $this->record('beta', [$this->stored(Issues::task(7), 'beta')], null);

        $this->record('alpha', [], null);

        self::assertCount(1, $this->store->issuesOf('beta'));
    }

    public function testAnIssueOfAnotherProjectIsRefusedAndNothingIsWritten(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], null);

        try {
            $this->record('alpha', [$this->stored(Issues::task(8), 'beta')], 'W/"a"');
            self::fail('An issue of another project was recorded.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertNull($this->store->syncStateOf('alpha')->validator);
    }

    public function testAListingWhoseWriteFailedLeavesNoRemovalForALaterFlush(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], 'W/"a"');
        $failing = new class {
            public function onFlush(): never
            {
                throw new RuntimeException('The flush failed after the removal was scheduled.');
            }
        };
        $events = $this->entities->getEventManager();
        $events->addEventListener([Events::onFlush], $failing);
        try {
            $this->record('alpha', [], 'W/"b"');
            self::fail('The write was expected to fail.');
        } catch (RuntimeException) {
        } finally {
            $events->removeEventListener([Events::onFlush], $failing);
        }

        $this->entities->flush();

        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testRecordingAListingLeavesAnotherCallersPendingWorkUnwritten(): void
    {
        $this->entities->persist(new IntakeValidator('beta', 'W/"pending"'));

        $this->record('alpha', [], 'W/"a"');

        self::assertSame(['alpha'], $this->entities->getConnection()->fetchFirstColumn('SELECT project FROM work_source_validator ORDER BY project'));
    }

    public function testTheValidatorIsStoredReplacedAndClearedWhenTheForgeSendsNone(): void
    {
        $this->record('alpha', [], 'W/"a"');
        $first = $this->store->syncStateOf('alpha')->validator;
        $this->record('alpha', [], 'W/"b"');
        $second = $this->store->syncStateOf('alpha')->validator;

        $this->record('alpha', [], null);

        self::assertSame(['W/"a"', 'W/"b"', null], [$first, $second, $this->store->syncStateOf('alpha')->validator]);
    }

    public function testTheSyncStateOfAProjectStaysWhenTheForgeSendsNoValidator(): void
    {
        $this->record('alpha', [], 'W/"a"');

        $this->record('alpha', [], null);

        self::assertSame(['alpha'], $this->entities->getConnection()->fetchFirstColumn('SELECT project FROM work_source_validator WHERE validator IS NULL'));
    }

    public function testAProjectNeverSyncedHasGenerationZeroAndEveryWinningWriteAdvancesIt(): void
    {
        $never = $this->store->syncStateOf('alpha')->generation;
        $this->record('alpha', [], null);
        $first = $this->store->syncStateOf('alpha')->generation;

        $this->record('alpha', [], 'W/"a"');

        self::assertSame([0, 1, 2], [$never, $first, $this->store->syncStateOf('alpha')->generation]);
    }

    public function testAWriteAskedWithAGenerationAnotherWorkerAdvancedMeanwhileWritesNothingAlsoWithoutAValidator(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], null);
        $readBefore = $this->store->syncStateOf('alpha');
        $this->record('alpha', [$this->stored(Issues::task(8))], null);

        $written = $this->store->recordIfStill('alpha', $readBefore, new ListingRows([$this->stored(Issues::task(9))], null));

        self::assertFalse($written);
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame(2, $this->store->syncStateOf('alpha')->generation);
    }

    public function testTheFirstWriteOfAProjectLosesWithoutAnExceptionWhenAnotherWorkerCommittedItsListingAfterTheSyncStateWasRead(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            $first->fetchOne('PRAGMA journal_mode=WAL');
            $events = new EventManager();
            $losing = new EntityManager($first, $this->entities->getConfiguration(), $events);
            new SchemaTool($losing)->createSchema([$losing->getClassMetadata(IntakeValidator::class), $losing->getClassMetadata(StoredIssue::class)]);
            $winner = new IntakeStore(new EntityManager($second, $this->entities->getConfiguration(), new EventManager()));
            $events->addEventListener([Events::onFlush], new readonly class($winner, $this->stored(Issues::task(8))) {
                public function __construct(private IntakeStore $winner, private StoredIssue $newer)
                {
                }

                public function onFlush(): void
                {
                    if (!$this->winner->recordIfStill('alpha', $this->winner->syncStateOf('alpha'), new ListingRows([$this->newer], 'W/"newer"'))) {
                        throw new LogicException('The other worker must commit first.');
                    }
                }
            });
            $loser = new IntakeStore($losing);

            $written = $loser->recordIfStill('alpha', $loser->syncStateOf('alpha'), new ListingRows([$this->stored(Issues::task(7))], null));

            self::assertFalse($written);
            self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $loser->issuesOf('alpha')));
            self::assertSame('W/"newer"', $loser->syncStateOf('alpha')->validator);
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    #[TestWith([false, false], 'first sync, default journal')]
    #[TestWith([true, false], 'synced project, default journal')]
    #[TestWith([false, true], 'first sync, WAL')]
    #[TestWith([true, true], 'synced project, WAL')]
    public function testAWriteRefusedWhileAnotherWorkerIsBetweenItsWriteAndItsCommitIsNamedAsHeldAndWritesNothing(bool $synced, bool $wal): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            if ($wal) {
                $first->fetchOne('PRAGMA journal_mode=WAL');
            }

            $events = new EventManager();
            $manager = new EntityManager($first, $this->entities->getConfiguration(), $events);
            new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
            $loser = new IntakeStore($manager);
            if ($synced) {
                self::assertTrue($loser->recordIfStill('alpha', $loser->syncStateOf('alpha'), new ListingRows([], 'old')));
            }

            $readBefore = $loser->syncStateOf('alpha');
            $events->addEventListener([Events::onFlush], new WriterBetweenWriteAndCommit($second));

            $held = $this->heldWhileRecording($loser, $readBefore);
            $second->commit();

            self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
            self::assertSame('Another writer holds the intake rows of project "alpha" and has not committed them.', $held->getMessage());
            self::assertInstanceOf(RetryableException::class, $held->getPrevious());
            self::assertSame('winner', $loser->syncStateOf('alpha')->validator);
            self::assertSame([], $loser->issuesOf('alpha'));
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    public function testACommitRefusedWhileAnotherWorkerReadsRollsBackItsGenerationAndTheNextWriteSucceeds(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            $first->executeStatement('PRAGMA busy_timeout=0');
            $events = new EventManager();
            $manager = new EntityManager($first, $this->entities->getConfiguration(), $events);
            new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
            $reading = new readonly class($second) {
                public function __construct(private Connection $other)
                {
                }

                public function onFlush(): void
                {
                    $this->other->beginTransaction();
                    $this->other->fetchOne('SELECT COUNT(*) FROM work_source_validator');
                }
            };
            $events->addEventListener([Events::onFlush], $reading);
            $store = new IntakeStore($manager);

            $held = $this->heldWhileRecording($store, $store->syncStateOf('alpha'));
            $second->rollBack();
            $events->removeEventListener([Events::onFlush], $reading);

            self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
            self::assertSame(0, $store->syncStateOf('alpha')->generation);
            self::assertSame(0, $first->getTransactionNestingLevel());
            self::assertTrue($store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([$this->stored(Issues::task(7))], 'after')));
            self::assertSame(1, $store->syncStateOf('alpha')->generation);
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    #[TestWith(['pdo_sqlite'])]
    #[TestWith(['sqlite3'])]
    public function testAPickReadWhoseCommitAReaderRefusedLeavesNoTransactionOpenAndTheNextOneReads(string $driver): void
    {
        $this->withAStoreAndAReaderOnOneFile($driver, function (IntakeStore $store, Connection $own, Connection $reader): void {
            $reader->beginTransaction();
            $reader->fetchOne('SELECT COUNT(*) FROM work_source_validator');
            $held = null;
            try {
                $store->failuresUnderTheListingLock('alpha');
            } catch (ProjectHeldByAnotherWriter $held) {
            }

            $reader->rollBack();

            self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
            self::assertInstanceOf(RetryableException::class, $held->getPrevious());
            self::assertSame(0, $own->getTransactionNestingLevel());
            self::assertSame([], $store->failuresUnderTheListingLock('alpha'));
            $store->failForGood('alpha', 7, 'recorded afterwards');
            self::assertSame([7 => 'recorded afterwards'], $store->failuresUnderTheListingLock('alpha'));
        });
    }

    #[TestWith(['pdo_sqlite'])]
    #[TestWith(['sqlite3'])]
    public function testAFailureWhoseCommitAReaderRefusedIsNotReadBackAndCanBeRecordedAgain(string $driver): void
    {
        $this->withAStoreAndAReaderOnOneFile($driver, function (IntakeStore $store, Connection $own, Connection $reader): void {
            $reader->beginTransaction();
            $reader->fetchOne('SELECT COUNT(*) FROM work_source_validator');
            $refused = null;
            try {
                $store->failForGood('alpha', 7, 'uncommitted');
            } catch (RetryableException $refused) {
            }

            $reader->rollBack();

            self::assertInstanceOf(RetryableException::class, $refused);
            self::assertSame([], $store->failuresOf('alpha'));
            self::assertSame(0, $own->getTransactionNestingLevel());
            $store->failForGood('alpha', 7, 'recorded afterwards');
            self::assertSame([7 => 'recorded afterwards'], $store->failuresOf('alpha'));
        });
    }

    // The journal mode is left at SQLite's default, because only there a reader's shared lock lets a write through and refuses its COMMIT.
    /**
     * @param callable(IntakeStore, Connection, Connection): void $scenario
     */
    private function withAStoreAndAReaderOnOneFile(string $driver, callable $scenario): void
    {
        if ('sqlite3' === $driver && !extension_loaded('sqlite3')) {
            self::markTestSkipped('The sqlite3 extension is not loaded.');
        }

        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $options = ['driver' => 'sqlite3' === $driver ? 'sqlite3' : 'pdo_sqlite', 'path' => $file];
        $own = DriverManager::getConnection($options);
        $reader = DriverManager::getConnection($options);
        try {
            $own->executeStatement('PRAGMA busy_timeout=0');
            $manager = new EntityManager($own, $this->entities->getConfiguration(), new EventManager());
            new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class), $manager->getClassMetadata(TaskFailure::class)]);
            $store = new IntakeStore($manager);
            self::assertTrue($store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([$this->stored(Issues::task(7))], null)));

            $scenario($store, $own, $reader);
        } finally {
            $reader->close();
            $own->close();
            unlink($file);
        }
    }

    public function testAWriteRefusedWhileAnotherWorkersCommitWaitsForThisOneIsNamedAsHeld(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            $first->executeStatement('PRAGMA busy_timeout=0');
            $second->executeStatement('PRAGMA busy_timeout=0');
            $events = new EventManager();
            $manager = new EntityManager($first, $this->entities->getConfiguration(), $events);
            new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
            $events->addEventListener([Events::onFlush], new readonly class($second) {
                public function __construct(private Connection $other)
                {
                }

                // The commit waits for the read lock of the store under test and keeps its pending lock, which also refuses the store's re-read of the sync state.
                public function onFlush(): void
                {
                    $this->other->executeStatement('BEGIN IMMEDIATE');
                    $this->other->executeStatement("INSERT INTO work_source_validator (project, validator, generation) VALUES ('alpha', 'winner', 1)");
                    try {
                        $this->other->executeStatement('COMMIT');
                    } catch (RetryableException) {
                        return;
                    }

                    throw new LogicException('The commit of the other worker must wait for the store under test.');
                }
            });
            $store = new IntakeStore($manager);

            $held = $this->heldWhileRecording($store, $store->syncStateOf('alpha'));

            self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
            self::assertInstanceOf(RetryableException::class, $held->getPrevious());
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    public function testADeadlockWhoseTransactionTheDatabaseAlreadyAbortedIsNamedAsHeldAndWritesNothing(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $events = new EventManager();
        $manager = new EntityManager($connection, $this->entities->getConfiguration(), $events);
        new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
        $events->addEventListener([Events::onFlush], new readonly class($connection) {
            public function __construct(private Connection $connection)
            {
            }

            // Rolled back here, because a server such as InnoDB aborts the victim's transaction itself before it reports the deadlock.
            public function onFlush(): never
            {
                $native = $this->connection->getNativeConnection();
                if ($native instanceof PDO) {
                    $native->rollBack();
                }

                throw new DeadlockException(Exception::new(new PDOException('Deadlock found when trying to get lock')), null);
            }
        });
        $store = new IntakeStore($manager);

        $held = $this->heldWhileRecording($store, $store->syncStateOf('alpha'));

        self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
        self::assertInstanceOf(DeadlockException::class, $held->getPrevious());
        self::assertSame(0, $store->syncStateOf('alpha')->generation);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testADeadlockOnAStatementOfTheFlushWhoseTransactionTheDatabaseAbortedIsNamedAsHeld(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $events = new EventManager();
        $manager = new EntityManager($connection, $this->entities->getConfiguration(), $events);
        new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
        $store = new IntakeStore($manager);
        self::assertTrue($store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([], null)));
        // On preUpdate and not onFlush, because only preUpdate runs inside the unit of work's own nested transaction, where a real statement deadlocks.
        $deadlock = new class($connection) {
            /** @var list<int> */
            public array $levels = [];

            public function __construct(private readonly Connection $connection)
            {
            }

            public function preUpdate(): never
            {
                $this->levels[] = $this->connection->getTransactionNestingLevel();
                $native = $this->connection->getNativeConnection();
                if ($native instanceof PDO) {
                    $native->rollBack();
                }

                throw new DeadlockException(Exception::new(new PDOException('Deadlock found when trying to get lock')), null);
            }
        };
        $events->addEventListener([Events::preUpdate], $deadlock);

        $held = null;
        try {
            (void) $store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([], 'new'));
        } catch (ProjectHeldByAnotherWriter $held) {
        }

        self::assertSame([2], $deadlock->levels);
        self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
        self::assertInstanceOf(DeadlockException::class, $held->getPrevious());
        self::assertSame(1, $store->syncStateOf('alpha')->generation);
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testADeadlockWhileThePickReadsTheFailuresUnderTheListingLockIsNamedAsHeld(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $events = new EventManager();
        $manager = new EntityManager($connection, $this->entities->getConfiguration(), $events);
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $store = new IntakeStore($manager);
        self::assertTrue($store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([], null)));
        // Rolled back here, because a server such as InnoDB aborts the victim's transaction itself before it reports the deadlock, so the rollback that follows is refused.
        $events->addEventListener([Events::postLoad], new CommitOnValidatorLoad(static function () use ($connection): void {
            $native = $connection->getNativeConnection();
            if ($native instanceof PDO) {
                $native->rollBack();
            }

            throw new DeadlockException(Exception::new(new PDOException('Deadlock found when trying to get lock')), null);
        }));

        $held = null;
        try {
            $store->failuresUnderTheListingLock('alpha');
        } catch (ProjectHeldByAnotherWriter $held) {
        }

        self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
        self::assertInstanceOf(DeadlockException::class, $held->getPrevious());
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testADeadlockRecordingAFailureLeavesNoTransactionOpenSoTheRetryIsStored(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $events = new EventManager();
        $manager = new EntityManager($connection, $this->entities->getConfiguration(), $events);
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $store = new IntakeStore($manager);
        self::assertTrue($store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([StoredIssue::of('alpha', new ItemReading(Issues::task(5), [], [], []))], null)));
        $store->failForGood('alpha', 5, 'original reason');
        // On preUpdate, because only preUpdate runs inside the unit of work's own nested transaction, whose refused rollback used to leave a level open.
        $deadlock = new readonly class($connection) {
            public function __construct(private Connection $connection)
            {
            }

            public function preUpdate(): never
            {
                $native = $this->connection->getNativeConnection();
                if ($native instanceof PDO) {
                    $native->rollBack();
                }

                throw new DeadlockException(Exception::new(new PDOException('Deadlock found when trying to get lock')), null);
            }
        };
        $events->addEventListener([Events::preUpdate], $deadlock);

        $failure = null;
        try {
            $store->failForGood('alpha', 5, 'not committed');
        } catch (DeadlockException $failure) {
        }
        $events->removeEventListener([Events::preUpdate], $deadlock);

        self::assertInstanceOf(DeadlockException::class, $failure);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame([5 => 'original reason'], $store->failuresOf('alpha'));
        $store->failForGood('alpha', 5, 'retry stored');
        self::assertSame([5 => 'retry stored'], $store->failuresOf('alpha'));
    }

    public function testAFirstWriteThatFailsWhileNoOtherWorkerStoredAListingLetsTheFailureThrough(): void
    {
        $inserting = new class {
            public function onFlush(OnFlushEventArgs $event): void
            {
                $event->getObjectManager()->getConnection()->executeStatement("INSERT INTO work_source_validator (project, validator, generation) VALUES ('alpha', NULL, 1)");
            }
        };
        $events = $this->entities->getEventManager();
        $events->addEventListener([Events::onFlush], $inserting);
        try {
            (void) $this->store->recordIfStill('alpha', SyncState::neverSynced(), new ListingRows([$this->stored(Issues::task(7))], null));
            self::fail('The failed write was expected to throw.');
        } catch (UniqueConstraintViolationException) {
        } finally {
            $events->removeEventListener([Events::onFlush], $inserting);
        }

        self::assertSame(0, $this->store->syncStateOf('alpha')->generation);
        self::assertSame([], $this->store->issuesOf('alpha'));
    }

    public function testAFirstWriteAskedAsNeverSyncedWritesNothingOnceAnotherWorkerSyncedTheProject(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], null);

        $written = $this->store->recordIfStill('alpha', SyncState::neverSynced(), new ListingRows([$this->stored(Issues::task(8))], null));

        self::assertFalse($written);
        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testARowStoredByAnotherWorkerSinceIsWhatIsReadBack(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7, ['intakt'], ['body' => 'Waits for #3']))], null);
        self::assertInstanceOf(StoredIssue::class, $this->entities->find(StoredIssue::class, ['project' => 'alpha', 'number' => 7]));
        $this->entities->getConnection()->executeStatement("UPDATE work_source_issue SET waits_for = '[5]' WHERE number = 7");

        self::assertSame([5], array_first($this->store->issuesOf('alpha'))?->queued()->waitsFor());
    }

    public function testEveryCallReleasesItsEntityManagerSoRepeatedTicksKeepNoRowsInMemory(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], 'W/"a"');
        $this->store->failForGood('alpha', 7, 'reason');
        $listener = new class {
            /** @var array<int, WeakReference<object>> */
            public array $managers = [];

            public function postLoad(PostLoadEventArgs $event): void
            {
                $manager = $event->getObjectManager();
                $this->managers[spl_object_id($manager)] = WeakReference::create($manager);
            }
        };
        $this->entities->getEventManager()->addEventListener([Events::postLoad], $listener);

        for ($tick = 0; $tick < 50; ++$tick) {
            $this->store->issuesOf('alpha');
            $this->store->failuresOf('alpha');
            $this->record('alpha', $this->store->issuesOf('alpha'), 'W/"a"');
        }

        $this->entities->getEventManager()->removeEventListener([Events::postLoad], $listener);
        gc_collect_cycles();

        self::assertNotSame([], $listener->managers);
        self::assertSame(0, count(array_filter($listener->managers, static fn (WeakReference $manager): bool => null !== $manager->get())), 'Entity managers of finished calls are still referenced.');
    }

    public function testAWriteAskedWithTheStoredGenerationReplacesTheRowsAndStoresTheNewValidator(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], 'W/"a"');

        $written = $this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows([$this->stored(Issues::task(8))], 'W/"b"'));

        self::assertTrue($written);
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame('W/"b"', $this->store->syncStateOf('alpha')->validator);
    }

    public function testAWriteAskedWithAValidatorAnotherWorkerReplacedMeanwhileWritesNothing(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], 'W/"older"');
        $readBefore = $this->store->syncStateOf('alpha');
        $this->record('alpha', [$this->stored(Issues::task(7))], 'W/"newer"');

        $written = $this->store->recordIfStill('alpha', $readBefore, new ListingRows([$this->stored(Issues::task(8))], 'W/"b"'));

        self::assertFalse($written);
        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame('W/"newer"', $this->store->syncStateOf('alpha')->validator);
    }

    public function testTheFailedForGoodReasonsOfAnotherProjectAreLeftOut(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], null);
        $this->record('beta', [$this->stored(Issues::task(9), 'beta')], null);
        $this->store->failForGood('alpha', 7, 'first');
        $this->store->failForGood('beta', 9, 'other');

        self::assertSame([7 => 'first'], $this->store->failuresOf('alpha'));
    }

    public function testRemovingTheRowOfAClosedTaskKeepsItsFailedForGoodReasonSoItsDependentsStayHeld(): void
    {
        $this->record('alpha', [$this->vouched(Issues::task(7)), $this->vouched(Issues::task(8, ['intakt'], ['body' => 'Waits for #7']))], null);
        $this->store->failForGood('alpha', 7, 'the build ran out of attempts');

        $this->record('alpha', [$this->vouched(Issues::task(8, ['intakt'], ['body' => 'Waits for #7']))], null);

        $failures = $this->store->failuresOf('alpha');
        $plan = new IntakeRules('intakt')->planOf(array_map(static fn (StoredIssue $issue): QueuedIssue => $issue->queued(), $this->store->issuesOf('alpha')), $failures);
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame([7 => 'the build ran out of attempts'], $failures);
        self::assertSame([], $plan->ready);
        self::assertSame(['depends on #7, which failed for good: the build ran out of attempts'], array_map(static fn ($held): string => $held->reason, $plan->held));
    }

    public function testFailingATaskForGoodAgainReplacesItsReason(): void
    {
        $this->record('alpha', [$this->stored(Issues::task(7))], null);
        $this->store->failForGood('alpha', 7, 'first');

        $this->store->failForGood('alpha', 7, 'second');

        self::assertSame([7 => 'second'], $this->store->failuresOf('alpha'));
    }

    public function testATaskTheStoredRowsDoNotShowCannotFailForGood(): void
    {
        $this->record('beta', [$this->stored(Issues::task(7), 'beta')], null);

        $this->expectException(TaskNotQueued::class);

        $this->store->failForGood('alpha', 7, 'reason');
    }

    public function testAnEntityManagerWhoseEventsCannotBeSharedIsRefused(): void
    {
        $this->expectException(LogicException::class);

        new IntakeStore(self::createStub(EntityManagerInterface::class))->issuesOf('alpha');
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->store = new IntakeStore($entities);
    }

    private function heldWhileRecording(IntakeStore $loser, SyncState $readBefore): ?ProjectHeldByAnotherWriter
    {
        try {
            (void) $loser->recordIfStill('alpha', $readBefore, new ListingRows([$this->stored(Issues::task(7))], null));
        } catch (ProjectHeldByAnotherWriter $held) {
            return $held;
        }

        return null;
    }

    private function stored(ListedIssue $listed, string $project = 'alpha'): StoredIssue
    {
        $references = new IssueReferences();

        return StoredIssue::of($project, new ItemReading($listed, $references->waitsFor($listed->body), $references->unreadableWaits($listed->body), $references->closedBy($listed->body)));
    }

    private function vouched(ListedIssue $listed): StoredIssue
    {
        $issue = $this->stored($listed);
        $history = new IssueHistory($listed->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), $listed->labels), $listed->body, $listed->labels);
        $issue->vouch(Vouching::of($listed->author, $history, static fn (string $account): bool => in_array($account, self::WRITERS, true)));

        return $issue;
    }

    /**
     * @param list<StoredIssue> $issues
     */
    private function record(string $project, array $issues, ?string $validator): void
    {
        $readBefore = $this->store->syncStateOf($project);

        self::assertTrue($this->store->recordIfStill($project, $readBefore, new ListingRows($issues, $validator)));
    }
}
