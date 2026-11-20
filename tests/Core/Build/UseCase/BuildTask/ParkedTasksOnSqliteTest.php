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

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-parked-tasks-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->root);
        $tool = new SchemaTool($this->manager());
        $tool->dropDatabase();
        $tool->createSchema($this->manager()->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testAParkedTaskIsStoredWithItsQuestionChoicesAndRecommendationAndAnAskedStep(): void
    {
        $this->parkedTasks()->park('sample', 42, BuildQuestion::fromHandedBack(self::QUESTION));

        $rows = $this->connection()->fetchAllAssociative('SELECT project, task, question, choices, recommendation, parked_at FROM build_parked_task');
        self::assertCount(1, $rows);
        // Decoded and converted, because MySQL normalizes stored JSON and drivers differ in how they return integers.
        self::assertSame(
            ['project' => 'sample', 'task' => 42, 'question' => 'Keep the old flag?', 'choices' => ['keep it', 'drop it'], 'recommendation' => 'drop it', 'parked_at' => '2026-11-20 10:00:00'],
            [...$rows[0], 'task' => (int) $rows[0]['task'], 'choices' => json_decode((string) $rows[0]['choices'], true, flags: JSON_THROW_ON_ERROR)],
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
            $this->connection()->fetchAllAssociative('SELECT question, recommendation FROM build_parked_task'),
        );
    }

    public function testTheLedgerRecordsTheFailedStepAfterAParkingWhoseCommitAReaderRefusedTookItsAskedEventBack(): void
    {
        if (!$this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Only SQLite refuses a commit while another connection holds a read transaction.');
        }

        $reader = $this->readerHoldingASharedLock();
        // No wait, because SQLite would otherwise wait its busy timeout before it refuses the commit.
        $this->connection()->executeStatement('PRAGMA busy_timeout = 0');

        $failure = $this->failureOfParking($this->parkedTasks());
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

    public function testADatabaseThatCannotBeOpenedLeavesTheTaskUnparkedWithFixedWords(): void
    {
        $unopenable = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root.'/missing/folder/data.db']);

        $failure = $this->failureOfParking(new ParkedTasks($unopenable, $this->ledger(), $this->registry(), $this->clock()));

        self::assertSame(self::NOT_PARKED, $failure);
        self::assertSame([], $this->ledgerRows());
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

    private function readerHoldingASharedLock(): Connection
    {
        $path = $this->connection()->getParams()['path'] ?? self::fail('The test database is not an SQLite file.');
        $reader = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $reader->beginTransaction();
        $reader->fetchOne('SELECT COUNT(*) FROM ledger_event');

        return $reader;
    }

    private function parkedTasks(): ParkedTasks
    {
        return new ParkedTasks($this->connection(), $this->ledger(), $this->registry(), $this->clock());
    }

    private function clock(): MockClock
    {
        return new MockClock('2026-11-20 11:00:00', 'Europe/Berlin');
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
}
