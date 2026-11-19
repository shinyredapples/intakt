<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
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
    public function testAParkingWhoseCommitIsRefusedByAReaderLeavesNoTransactionOpenAndKeepsTheEarlierQuestion(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $connection = $entities->getConnection();
        $parked = new ParkedTasks($connection, new MockClock('2026-11-19T10:00:00+00:00'));
        $parked->park($this->request(), $this->question('Which store?'), static function (): void {});
        $connection->executeStatement('PRAGMA busy_timeout = 0');
        $file = $connection->fetchAssociative('PRAGMA database_list');
        self::assertIsArray($file);
        self::assertIsString($file['file']);
        $reader = new PDO('sqlite:'.$file['file']);
        $reader->beginTransaction();
        $reader->query('SELECT COUNT(*) FROM '.ParkedTaskRecord::TABLE);

        try {
            $parked->park($this->request(), $this->question('Which store now?'), static function (): void {});
            self::fail('A parking whose commit a reader blocked went through.');
        } catch (TaskNotParked $unparked) {
            self::assertSame('issue 7 of project "alpha" could not be parked', $unparked->getMessage());
        } finally {
            $reader->rollBack();
        }

        $native = $connection->getNativeConnection();
        self::assertInstanceOf(PDO::class, $native);
        self::assertSame(
            [false, false, 'Which store?'],
            [$connection->isTransactionActive(), $native->inTransaction(), $connection->fetchOne('SELECT question FROM '.ParkedTaskRecord::TABLE)],
        );
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
