<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
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
#[UsesClass(BuildQuestion::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(EventRecord::class)]
final class ParkedTasksOnSqliteTest extends KernelTestCase
{
    private const string QUESTION = '{"question":"Keep the old flag?","choices":["keep it","drop it"],"recommendation":"drop it"}';

    private const string NOT_PARKED = 'Issue 42 of project "sample" was not parked: the database did not store its question';

    private string $root = '';

    public function testAParkedTaskIsStoredWithItsQuestionChoicesAndRecommendationAndAnAskedStep(): void
    {
        $this->parkedTasks()->park('sample', 42, BuildQuestion::fromHandedBack(self::QUESTION));

        self::assertSame(
            [['project' => 'sample', 'task' => 42, 'question' => 'Keep the old flag?', 'choices' => ['keep it', 'drop it'], 'recommendation' => 'drop it', 'parked_at' => '2026-11-20 10:00:00']],
            $this->parkedRows(),
        );
        self::assertSame(
            [['kind' => 'build_step_ended', 'payload' => '{"project":"sample","task":"42","step":"build_job","outcome":"asked","failure":null}']],
            $this->ledgerRows(),
        );
    }

    public function testATaskParkedAgainKeepsOneRowWithTheNewerQuestion(): void
    {
        $parked = $this->parkedTasks();
        $parked->park('sample', 42, BuildQuestion::fromHandedBack(self::QUESTION));

        $parked->park('sample', 42, BuildQuestion::fromHandedBack('{"question":"Which name?","choices":["a","b"],"recommendation":"a"}'));

        self::assertSame(
            [['question' => 'Which name?', 'recommendation' => 'a']],
            array_map(static fn (array $row): array => ['question' => $row['question'], 'recommendation' => $row['recommendation']], $this->parkedRows()),
        );
    }

    public function testAQuestionWithANulCharacterInsideItsTextsIsStoredWhole(): void
    {
        $this->parkedTasks()->park('sample', 42, BuildQuestion::fromHandedBack('{"question":"Keep a\u0000b?","choices":["a\u0000b","c"],"recommendation":"a\u0000b"}'));

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
        $parked->park('sample', 42, BuildQuestion::fromHandedBack(self::QUESTION));
        $this->connection()->executeStatement("CREATE TRIGGER refuse_event BEFORE INSERT ON ledger_event BEGIN SELECT RAISE(ROLLBACK, 'refused'); END");

        $failure = $this->failureOfParking($parked);
        $native = $this->connection()->getNativeConnection();

        self::assertSame(self::NOT_PARKED, $failure);
        self::assertInstanceOf(PDO::class, $native);
        self::assertSame([false, false], [$this->connection()->isTransactionActive(), $native->inTransaction()]);
        self::assertSame(['Keep the old flag?'], array_column($this->parkedRows(), 'question'));
        self::assertCount(1, $this->ledgerRows());
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

    private function parkedTasks(): ParkedTasks
    {
        return new ParkedTasks($this->connection(), $this->ledger(), $this->registry(), $this->clock());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parkedRows(): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT project, task, question, choices, recommendation, parked_at FROM build_parked_task');

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
            $parked->park('sample', 42, BuildQuestion::fromHandedBack(self::QUESTION));
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
