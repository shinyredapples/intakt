<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(ParkedTasks::class)]
#[CoversClass(ParkedTaskRecord::class)]
#[CoversClass(TaskNotParked::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(BuildTaskRequest::class)]
final class ParkedTasksTest extends KernelTestCase
{
    private EntityManagerInterface $entities;

    private MockClock $clock;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->entities = $entities;
        $this->clock = new MockClock('2026-11-19T10:00:00+00:00');
    }

    public function testAParkedTaskStoresItsQuestionChoicesRecommendationAndParkingTime(): void
    {
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'), $this->recorded(...));

        self::assertSame(
            ['project' => 'alpha', 'task' => 7, 'question' => 'Which store?', 'choices' => ['SQLite', 'Postgres'], 'recommendation' => 'SQLite', 'parked_at' => '2026-11-19T10:00:00+00:00'],
            $this->storedRow(7),
        );
    }

    public function testParkingTheSameTaskAgainKeepsOneRecordWithTheLatestQuestion(): void
    {
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'), $this->recorded(...));
        $this->clock->modify('+1 hour');

        $this->parked()->park($this->request(7), $this->question('Which store now?', 'Postgres'), $this->recorded(...));

        self::assertSame(1, $this->storedCount());
        self::assertSame(['Which store now?', 'Postgres', '2026-11-19T11:00:00+00:00'], [$this->storedRow(7)['question'], $this->storedRow(7)['recommendation'], $this->storedRow(7)['parked_at']]);
    }

    public function testAnotherTaskOfTheSameProjectIsParkedBesideTheFirst(): void
    {
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'), $this->recorded(...));

        $this->parked()->park($this->request(8), $this->question('Which queue?', 'Postgres'), $this->recorded(...));

        self::assertSame(['Which store?', 'Which queue?'], [$this->storedRow(7)['question'], $this->storedRow(8)['question']]);
    }

    public function testATaskThatCannotBeStoredIsNotParkedAndTheFailureQuotesNothingOfTheQuestion(): void
    {
        $this->entities->getConnection()->executeStatement('DROP TABLE '.ParkedTaskRecord::TABLE);

        try {
            $this->parked()->park($this->request(7), $this->question('PLANT-question', 'SQLite'), $this->recorded(...));
            self::fail('A task without its table was parked.');
        } catch (TaskNotParked $unparked) {
            self::assertSame('issue 7 of project "alpha" could not be parked', $unparked->getMessage());
            self::assertNotNull($unparked->getPrevious());
        }
    }

    public function testAParkingWhoseEventIsNotRecordedTakesTheNewQuestionBackAndKeepsTheEarlierOne(): void
    {
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'), $this->recorded(...));

        try {
            $this->parked()->park($this->request(7), $this->question('Which store now?', 'Postgres'), static fn (): never => throw new RuntimeException('the ledger is down'));
            self::fail('A parking whose event failed went through.');
        } catch (RuntimeException $failure) {
            self::assertSame('the ledger is down', $failure->getMessage());
        }

        self::assertSame(['Which store?', 'SQLite'], [$this->storedRow(7)['question'], $this->storedRow(7)['recommendation']]);
    }

    public function testTheParkingEventIsRecordedWhileTheNewRowIsInTheOpenTransaction(): void
    {
        $connection = $this->entities->getConnection();
        $seen = [];

        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'), static function () use ($connection, &$seen): void {
            $seen = [$connection->isTransactionActive(), $connection->fetchOne('SELECT question FROM '.ParkedTaskRecord::TABLE)];
        });

        self::assertSame([true, 'Which store?'], $seen);
    }

    #[TestWith(['{"question":"Which\u0000 store?","choices":["SQLite","Postgres"],"recommendation":"SQLite"}'])]
    #[TestWith(['{"question":"Which store?","choices":["SQLite","Post\u0000gres"],"recommendation":"SQLite"}'])]
    #[TestWith(['{"question":"Which store?","choices":["SQL\u0000ite","Postgres"],"recommendation":"SQL\u0000ite"}'])]
    public function testAQuestionHoldingANulCharacterIsNotParkedAndNothingIsStoredOrRecorded(string $text): void
    {
        $recorded = false;

        try {
            $this->parked()->park($this->request(7), BuildQuestion::fromHandedBack($text), static function () use (&$recorded): void {
                $recorded = true;
            });
            self::fail('A question holding NUL was parked.');
        } catch (TaskNotParked $unparked) {
            self::assertSame('issue 7 of project "alpha" could not be parked: its question holds a NUL character', $unparked->getMessage());
        }

        self::assertSame([false, 0], [$recorded, $this->storedCount()]);
    }

    // Read as a number, because PostgreSQL and MySQL may hand a count back as a string.
    private function storedCount(): int
    {
        $count = $this->entities->getConnection()->fetchOne('SELECT COUNT(*) FROM '.ParkedTaskRecord::TABLE);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function recorded(): void
    {
    }

    private function parked(): ParkedTasks
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new ParkedTasks($this->entities->getConnection(), $this->clock, $registry);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $task): array
    {
        $this->entities->clear();
        $record = $this->entities->find(ParkedTaskRecord::class, ['project' => 'alpha', 'task' => $task]);
        self::assertInstanceOf(ParkedTaskRecord::class, $record);
        $row = $record->row();

        return array_replace($row, ['parked_at' => $row['parked_at']->format(DATE_ATOM)]);
    }

    private function question(string $text, string $recommendation): BuildQuestion
    {
        return BuildQuestion::fromHandedBack((string) json_encode(['question' => $text, 'choices' => ['SQLite', 'Postgres'], 'recommendation' => $recommendation]));
    }

    private function request(int $issue): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', $issue, 'remote', 'feat/task', 'clone', 'intakt-job-alpha:1');
    }
}
