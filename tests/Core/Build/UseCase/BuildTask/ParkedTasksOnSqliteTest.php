<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

// Outside the persistence group, because a reader that refuses a COMMIT is SQLite's file locking, which the other engines do not have.
#[CoversClass(ParkedTasks::class)]
#[CoversClass(TaskNotParked::class)]
#[UsesClass(ParkedTaskRecord::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BuildTaskRequest::class)]
final class ParkedTasksOnSqliteTest extends KernelTestCase
{
    private Connection $connection;

    private ParkedTasks $parked;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->connection = $entities->getConnection();
        $this->parked = new ParkedTasks($this->connection, new MockClock('2026-11-19T10:00:00+00:00'), $registry);
    }

    public function testAParkingWhoseCommitIsRefusedByAReaderLeavesNoTransactionOpenAndKeepsTheEarlierQuestion(): void
    {
        $this->parked->park($this->request(), $this->question('Which store?'), static function (): void {});

        $this->parkWhileAReaderHoldsTheDatabase(static function (): void {});

        $native = $this->connection->getNativeConnection();
        self::assertInstanceOf(PDO::class, $native);
        self::assertSame(
            [false, false, 'Which store?'],
            [$this->connection->isTransactionActive(), $native->inTransaction(), $this->connection->fetchOne('SELECT question FROM '.ParkedTaskRecord::TABLE)],
        );
    }

    public function testTheLedgerRecordsTheFailedStepAfterAParkingWhoseCommitAReaderRefusedTookItsAskedEventBack(): void
    {
        $ledger = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);

        $this->parkWhileAReaderHoldsTheDatabase(static function () use ($ledger): void {
            (void) $ledger->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, '{"step":"build_job","outcome":"asked"}'));
        });
        $failed = $ledger->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, '{"step":"build_job","outcome":"failed"}'));

        self::assertSame([true, ['{"step":"build_job","outcome":"failed"}']], [$failed->succeeded(), array_map(static fn (array $row): mixed => $row['payload'], $this->connection->fetchAllAssociative('SELECT payload FROM ledger_event'))]);
    }

    public function testADatabaseThatCannotBeOpenedLeavesTheTaskUnparkedWithFixedWords(): void
    {
        $unreachable = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir().'/intakt-missing-'.bin2hex(random_bytes(6)).'/database.sqlite']);
        $parked = new ParkedTasks($unreachable, new MockClock(), self::createStub(ManagerRegistry::class));

        $this->expectExceptionObject(new TaskNotParked('issue 7 of project "alpha" could not be parked'));

        $parked->park($this->request(), $this->question('Which store?'), static function (): void {});
    }

    /**
     * @param Closure(): void $recordParking
     */
    private function parkWhileAReaderHoldsTheDatabase(Closure $recordParking): void
    {
        $this->connection->executeStatement('PRAGMA busy_timeout = 0');
        $file = $this->connection->fetchAssociative('PRAGMA database_list');
        self::assertIsArray($file);
        self::assertIsString($file['file']);
        $reader = new PDO('sqlite:'.$file['file']);
        $reader->beginTransaction();
        $reader->query('SELECT COUNT(*) FROM '.ParkedTaskRecord::TABLE);

        try {
            $this->parked->park($this->request(), $this->question('Which store now?'), $recordParking);
            self::fail('A parking whose commit a reader blocked went through.');
        } catch (TaskNotParked $unparked) {
            self::assertSame('issue 7 of project "alpha" could not be parked', $unparked->getMessage());
        } finally {
            $reader->rollBack();
        }
    }

    private function question(string $text): BuildQuestion
    {
        return BuildQuestion::fromHandedBack((string) json_encode(['question' => $text, 'choices' => ['SQLite', 'Postgres'], 'recommendation' => 'SQLite']));
    }

    private function request(): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', 7, 'remote', 'feat/task', 'clone', 'intakt-job-alpha:1');
    }
}
