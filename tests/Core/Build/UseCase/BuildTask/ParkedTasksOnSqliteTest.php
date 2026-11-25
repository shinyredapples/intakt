<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTaskNotCleared;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Tests\Support\KernelTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

// On the install's own database, because the row and the asked step share its transaction; the refused commit runs on SQLite only, because it is SQLite's lock answer.
#[Group('persistence')]
#[CoversClass(ParkedTasks::class)]
#[CoversClass(ParkedTaskRecord::class)]
#[CoversClass(TaskNotParked::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(PayloadFields::class)]
#[CoversClass(ParkedTaskNotCleared::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BuildRun::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(EventRecord::class)]
final class ParkedTasksOnSqliteTest extends KernelTestCase
{
    private const string QUESTION = '{"question":"Keep the old flag?","choices":["keep it","drop it"],"recommendation":"drop it"}';

    private const string NOT_PARKED = 'Issue 42 of project "sample" was not parked: the database did not store its question';

    private const string NEWER_QUESTION = '{"question":"Which name?","choices":["a","b"],"recommendation":"a"}';

    // The newer run's id sorts first, so only the order of the ledger's rows can tell which run started later.
    private const string OLDER_RUN = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000002';

    private const string NEWER_RUN = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000001';

    private const string FIRST_RUN = 'f1d0e2c4-5b6a-4c8d-9e0f-000000000003';

    private string $root = '';

    public function testAParkedTaskIsStoredWithItsQuestionChoicesAndRecommendationAndAnAskedStep(): void
    {
        $this->parkedTasks()->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack(self::QUESTION));

        self::assertSame(
            [['project' => 'sample', 'task' => 42, 'question' => 'Keep the old flag?', 'choices' => ['keep it', 'drop it'], 'recommendation' => 'drop it', 'parked_at' => '2026-11-20 10:00:00', 'run' => 'run-1']],
            $this->parkedRows(),
        );
        self::assertSame(
            [
                ['kind' => 'build_step_ended', 'payload' => '{"project":"sample","task":"42","run":"run-1","step":"build_job","outcome":"asked","failure":null}'],
                ['kind' => 'task_parked', 'payload' => '{"project":"sample","task":"42","run":"run-1"}'],
            ],
            $this->ledgerRows(),
        );
    }

    public function testAClearedTaskLosesItsRowWhileAnotherTaskKeepsItsOwn(): void
    {
        $parked = $this->parkedTasks();
        $parked->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack(self::QUESTION));
        $parked->park(new BuildRun('sample', 43, 'run-2'), BuildQuestion::fromHandedBack(self::QUESTION));

        $parked->clear(new BuildRun('sample', 42, 'run-1'));

        self::assertSame([43], array_column($this->parkedRows(), 'task'));
    }

    public function testClearingATaskThatIsNotParkedIsNoFailure(): void
    {
        $this->parkedTasks()->clear(new BuildRun('sample', 42, 'run-1'));

        self::assertSame([], $this->parkedRows());
    }

    public function testAClearingTheDatabaseRefusesFailsWithFixedWordsAndNoCause(): void
    {
        $unopenable = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root.'/missing/folder/data.db']);

        try {
            new ParkedTasks($unopenable, $this->ledger(), $this->registry(), $this->clock())->clear(new BuildRun('sample', 42, 'run-1'));
            self::fail('The parked row was cleared.');
        } catch (ParkedTaskNotCleared $notCleared) {
            self::assertSame(['Issue 42 of project "sample" is still parked: the database did not remove its question', null], [$notCleared->getMessage(), $notCleared->getPrevious()]);
        }
    }

    public function testATaskParkedAgainKeepsOneRowWithTheNewerQuestion(): void
    {
        $parked = $this->parkedTasks();
        $parked->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack(self::QUESTION));

        $parked->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack('{"question":"Which name?","choices":["a","b"],"recommendation":"a"}'));

        self::assertSame(
            [['question' => 'Which name?', 'recommendation' => 'a']],
            array_map(static fn (array $row): array => ['question' => $row['question'], 'recommendation' => $row['recommendation']], $this->parkedRows()),
        );
    }

    public function testAnOlderRunThatParksAfterANewerOneKeepsTheNewerQuestionAndStillRecordsItsStepAndEvent(): void
    {
        $this->startBothRuns();
        $parked = $this->parkedTasks();
        $parked->park($this->newerRun(), BuildQuestion::fromHandedBack(self::NEWER_QUESTION));

        $parked->park($this->olderRun(), BuildQuestion::fromHandedBack(self::QUESTION));

        self::assertSame([['Which name?', self::NEWER_RUN]], $this->parkedQuestionsWithRuns());
        self::assertSame(
            [
                ['kind' => 'build_step_ended', 'payload' => '{"project":"sample","task":"42","run":"'.self::OLDER_RUN.'","step":"build_job","outcome":"asked","failure":null}'],
                ['kind' => 'task_parked', 'payload' => '{"project":"sample","task":"42","run":"'.self::OLDER_RUN.'"}'],
            ],
            array_slice($this->ledgerRows(), -2),
        );
    }

    public function testANewerRunThatParksAfterAnOlderOneReplacesTheOlderQuestion(): void
    {
        $this->startBothRuns();
        $parked = $this->parkedTasks();
        $parked->park($this->olderRun(), BuildQuestion::fromHandedBack(self::QUESTION));

        $parked->park($this->newerRun(), BuildQuestion::fromHandedBack(self::NEWER_QUESTION));

        self::assertSame([['Which name?', self::NEWER_RUN]], $this->parkedQuestionsWithRuns());
    }

    public function testANewerRunKeepsItsQuestionWhenAnOlderOneParksBetweenItsReadAndItsWrite(): void
    {
        $first = new BuildRun('sample', 42, self::FIRST_RUN);
        $this->appendTaskStep($first);
        $this->startBothRuns();
        $parked = $this->parkedTasks();
        $parked->park($first, BuildQuestion::fromHandedBack(self::QUESTION));

        $this->parkedTasksWhoseOrderLookupFirst(fn () => $parked->park($this->olderRun(), BuildQuestion::fromHandedBack(self::QUESTION)))
            ->park($this->newerRun(), BuildQuestion::fromHandedBack(self::NEWER_QUESTION));

        self::assertSame([['Which name?', self::NEWER_RUN]], $this->parkedQuestionsWithRuns());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(
            [
                ['kind' => 'build_step_ended', 'payload' => '{"project":"sample","task":"42","run":"'.self::NEWER_RUN.'","step":"build_job","outcome":"asked","failure":null}'],
                ['kind' => 'task_parked', 'payload' => '{"project":"sample","task":"42","run":"'.self::NEWER_RUN.'"}'],
            ],
            array_slice($this->ledgerRows(), -2),
        );
    }

    public function testARunWhoseRowAnotherOneReplacesAtEveryAttemptIsNotParked(): void
    {
        $this->startBothRuns();
        $this->parkedTasks()->park($this->olderRun(), BuildQuestion::fromHandedBack(self::QUESTION));
        $before = $this->ledgerRows();
        $lookups = 0;
        $replaced = $this->parkedTasksWhoseOrderLookupFirst(function () use (&$lookups): void {
            $this->connection()->executeStatement('UPDATE build_parked_task SET run = ?', [sprintf('f1d0e2c4-5b6a-4c8d-9e0f-00000000010%d', ++$lookups)]);
        });

        $failure = $this->failureOfParking($replaced);

        self::assertSame([self::NOT_PARKED, 3], [$failure, $lookups]);
        self::assertSame([['Keep the old flag?', 'f1d0e2c4-5b6a-4c8d-9e0f-000000000103']], $this->parkedQuestionsWithRuns());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame($before, $this->ledgerRows());
    }

    public function testAnOlderRunThatBuiltAfterANewerOneParkedKeepsTheNewerQuestion(): void
    {
        $this->startBothRuns();
        $parked = $this->parkedTasks();
        $parked->park($this->newerRun(), BuildQuestion::fromHandedBack(self::NEWER_QUESTION));

        $parked->clear($this->olderRun());

        self::assertSame([['Which name?', self::NEWER_RUN]], $this->parkedQuestionsWithRuns());
    }

    public function testANewerRunThatBuiltAfterAnOlderOneParkedClearsTheOlderQuestion(): void
    {
        $this->startBothRuns();
        $parked = $this->parkedTasks();
        $parked->park($this->olderRun(), BuildQuestion::fromHandedBack(self::QUESTION));

        $parked->clear($this->newerRun());

        self::assertSame([], $this->parkedRows());
    }

    public function testARunOfAnotherTaskWithTheSameIdsSaysNothingAboutTheOrder(): void
    {
        $this->appendTaskStep(new BuildRun('sample', 43, self::OLDER_RUN));
        $this->appendTaskStep(new BuildRun('other', 42, self::OLDER_RUN));
        $this->appendTaskStep($this->newerRun());
        $this->appendTaskStep($this->olderRun());
        $parked = $this->parkedTasks();
        $parked->park($this->newerRun(), BuildQuestion::fromHandedBack(self::NEWER_QUESTION));

        $parked->clear($this->olderRun());

        self::assertSame([], $this->parkedRows());
    }

    public function testARowParkedBeforeRowsNamedTheirRunIsReplacedByAnyParkingAndClearedByAnyBuild(): void
    {
        $this->startBothRuns();
        $parked = $this->parkedTasks();
        $parked->park($this->olderRun(), BuildQuestion::fromHandedBack(self::QUESTION));
        $this->connection()->executeStatement('UPDATE build_parked_task SET run = NULL');

        $parked->park($this->olderRun(), BuildQuestion::fromHandedBack(self::NEWER_QUESTION));
        $replaced = $this->parkedQuestionsWithRuns();
        $this->connection()->executeStatement('UPDATE build_parked_task SET run = NULL');
        $parked->clear($this->olderRun());

        self::assertSame([['Which name?', self::OLDER_RUN]], $replaced);
        self::assertSame([], $this->parkedRows());
    }

    public function testAQuestionWithANulCharacterInsideItsTextsIsStoredWhole(): void
    {
        $this->parkedTasks()->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack('{"question":"Keep a\u0000b?","choices":["a\u0000b","c"],"recommendation":"a\u0000b"}'));

        self::assertSame(
            [['question' => "Keep a\0b?", 'choices' => ["a\0b", 'c'], 'recommendation' => "a\0b"]],
            array_map(static fn (array $row): array => ['question' => $row['question'], 'choices' => $row['choices'], 'recommendation' => $row['recommendation']], $this->parkedRows()),
        );
    }

    public function testAParkingInsideACallersTransactionIsRefusedBeforeItWritesAndLeavesThatTransactionOpen(): void
    {
        $this->connection()->beginTransaction();

        $failure = $this->failureOfParking($this->parkedTasks());
        $nesting = $this->connection()->getTransactionNestingLevel();
        $this->connection()->rollBack();

        self::assertSame([self::NOT_PARKED, 1], [$failure, $nesting]);
        self::assertSame([], $this->parkedRows());
        self::assertSame([], $this->ledgerRows());
    }

    public function testAParkingWhoseTransactionTheDatabaseRolledBackLeavesNoTransactionOpenAndTheEarlierQuestionStored(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The trigger that rolls the whole transaction back is written in SQLite.');
        }

        $parked = $this->parkedTasks();
        $parked->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack(self::QUESTION));
        $this->connection()->executeStatement("CREATE TRIGGER refuse_event BEFORE INSERT ON ledger_event BEGIN SELECT RAISE(ROLLBACK, 'refused'); END");

        $failure = $this->failureOfParking($parked);
        $native = $this->connection()->getNativeConnection();

        self::assertSame(self::NOT_PARKED, $failure);
        self::assertInstanceOf(PDO::class, $native);
        self::assertSame([false, false], [$this->connection()->isTransactionActive(), $native->inTransaction()]);
        self::assertSame(['Keep the old flag?'], array_column($this->parkedRows(), 'question'));
        self::assertCount(2, $this->ledgerRows());
    }

    public function testTheLedgerRecordsTheFailedStepAfterAParkingWhoseCommitAReaderRefusedTookItsAskedEventBack(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Only SQLite refuses a commit while another connection holds a read transaction.');
        }

        $reader = $this->readerHoldingASharedLock();
        $failure = $this->failureOfParkingWhileTheReaderHolds();
        $reader->rollBack();
        $reader->close();
        $failed = $this->ledger()->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, '{"project":"sample","task":"42","step":"build_job","outcome":"failed","failure":"later"}'));

        self::assertSame(self::NOT_PARKED, $failure);
        self::assertTrue($failed->succeeded(), (string) $failed->failure);
        self::assertSame([], $this->connection()->fetchAllAssociative('SELECT project FROM build_parked_task'));
        self::assertSame(
            [['kind' => 'build_step_ended', 'payload' => '{"project":"sample","task":"42","step":"build_job","outcome":"failed","failure":"later"}']],
            $this->ledgerRows(),
        );
        self::assertCount(1, $this->manager()->getUnitOfWork()->getIdentityMap()[EventRecord::class] ?? []);
    }

    public function testAParkingWhoseCommitAReaderRefusedIsRefusedAlsoWhenAnEarlierTestLeftTheDatabaseInWalMode(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Only SQLite has a journal mode.');
        }

        self::assertSame('wal', $this->connection()->fetchOne('PRAGMA journal_mode=WAL'));
        $this->resetDatabase();
        $reader = $this->readerHoldingASharedLock();

        $failure = $this->failureOfParkingWhileTheReaderHolds();
        $reader->rollBack();
        $reader->close();

        self::assertSame(self::NOT_PARKED, $failure);
    }

    public function testADatabaseThatCannotBeOpenedLeavesTheTaskUnparkedWithFixedWords(): void
    {
        $unopenable = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root.'/missing/folder/data.db']);

        $failure = $this->failureOfParking(new ParkedTasks($unopenable, $this->ledger(), $this->registry(), $this->clock()));

        self::assertSame(self::NOT_PARKED, $failure);
        self::assertSame([], $this->ledgerRows());
    }

    public function testAParkingWhoseLedgerDidNotRecordTheAskedStepLeavesTheTaskUnparkedAndNoTransactionOpen(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturn(AppendResult::failed('the ledger refused the event'));

        $failure = $this->failureOfParking(new ParkedTasks($this->connection(), $ledger, $this->registry(), $this->clock()));

        self::assertSame(self::NOT_PARKED, $failure);
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame([], $this->parkedRows());
        self::assertSame([], $this->ledgerRows());
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-parked-tasks-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->root);
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    // The rollback journal is set, because the database file outlives a test and a review test may have left it in WAL, where a reader does not refuse a commit.
    private function resetDatabase(): void
    {
        if ($this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::assertSame('delete', $this->connection()->fetchOne('PRAGMA journal_mode=DELETE'));
        }

        $tool = new SchemaTool($this->manager());
        $tool->dropDatabase();
        $tool->createSchema($this->manager()->getMetadataFactory()->getAllMetadata());
    }

    private function readerHoldingASharedLock(): Connection
    {
        $path = $this->connection()->getParams()['path'] ?? self::fail('The test database is not an SQLite file.');
        $reader = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $reader->beginTransaction();
        $reader->fetchOne('SELECT COUNT(*) FROM ledger_event');

        return $reader;
    }

    private function failureOfParkingWhileTheReaderHolds(): string
    {
        // No wait, because SQLite would otherwise wait its busy timeout before it refuses the commit.
        $this->connection()->executeStatement('PRAGMA busy_timeout = 0');

        return $this->failureOfParking($this->parkedTasks());
    }

    private function startBothRuns(): void
    {
        $this->appendTaskStep($this->olderRun());
        $this->appendTaskStep($this->newerRun());
    }

    private function appendTaskStep(BuildRun $run): void
    {
        $appended = $this->ledger()->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, $run->stepPayload(BuildStep::Task, BuildStepOutcome::Passed, null)));
        self::assertTrue($appended->succeeded(), (string) $appended->failure);
    }

    private function newerRun(): BuildRun
    {
        return new BuildRun('sample', 42, self::NEWER_RUN);
    }

    private function olderRun(): BuildRun
    {
        return new BuildRun('sample', 42, self::OLDER_RUN);
    }

    // The other run's write is forced into the lookup of the order, because that lookup stands between the parking's read of the row and its own write.
    private function parkedTasksWhoseOrderLookupFirst(callable $interleaved): ParkedTasks
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback($this->ledger()->append(...));
        $ledger->method('readOfKindsMentioning')->willReturnCallback(function (Mentions $runs, LedgerEventKind ...$kinds) use ($interleaved): RecordedEvents {
            $interleaved();

            return $this->ledger()->readOfKindsMentioning($runs, ...$kinds);
        });

        return new ParkedTasks($this->connection(), $ledger, $this->registry(), $this->clock());
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function parkedQuestionsWithRuns(): array
    {
        return array_map(static fn (array $row): array => [$row['question'], $row['run']], $this->parkedRows());
    }

    private function parkedTasks(): ParkedTasks
    {
        return new ParkedTasks($this->connection(), $this->ledger(), $this->registry(), $this->clock());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parkedRows(): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT project, task, question, choices, recommendation, parked_at, run FROM build_parked_task');

        // Decoded and converted, because MySQL normalizes stored JSON and drivers differ in how they return integers.
        return array_map(static fn (array $row): array => [
            ...$row,
            'task' => is_numeric($row['task']) ? (int) $row['task'] : $row['task'],
            'question' => is_string($row['question']) ? json_decode($row['question'], true, flags: JSON_THROW_ON_ERROR) : $row['question'],
            'choices' => is_string($row['choices']) ? json_decode($row['choices'], true, flags: JSON_THROW_ON_ERROR) : $row['choices'],
            'recommendation' => is_string($row['recommendation']) ? json_decode($row['recommendation'], true, flags: JSON_THROW_ON_ERROR) : $row['recommendation'],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT kind, payload FROM ledger_event ORDER BY position');
    }

    private function ledger(): Ledger
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);

        return $ledger;
    }

    private function connection(): Connection
    {
        return $this->manager()->getConnection();
    }

    private function failureOfParking(ParkedTasks $parked): string
    {
        try {
            $parked->park(new BuildRun('sample', 42, 'run-1'), BuildQuestion::fromHandedBack(self::QUESTION));
        } catch (TaskNotParked $notParked) {
            self::assertNull($notParked->getPrevious());

            return $notParked->getMessage();
        }

        self::fail('The task was parked.');
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry()->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function registry(): ManagerRegistry
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function clock(): MockClock
    {
        return new MockClock('2026-11-20 11:00:00', 'Europe/Berlin');
    }
}
