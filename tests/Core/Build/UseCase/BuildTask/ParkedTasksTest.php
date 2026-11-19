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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
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
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'));

        self::assertSame(
            ['project' => 'alpha', 'task' => 7, 'question' => 'Which store?', 'choices' => ['SQLite', 'Postgres'], 'recommendation' => 'SQLite', 'parked_at' => '2026-11-19T10:00:00+00:00'],
            $this->storedRow(7),
        );
    }

    public function testParkingTheSameTaskAgainKeepsOneRecordWithTheLatestQuestion(): void
    {
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'));
        $this->clock->modify('+1 hour');

        $this->parked()->park($this->request(7), $this->question('Which store now?', 'Postgres'));

        self::assertSame(1, $this->entities->getConnection()->fetchOne('SELECT COUNT(*) FROM '.ParkedTaskRecord::TABLE));
        self::assertSame(['Which store now?', 'Postgres', '2026-11-19T11:00:00+00:00'], [$this->storedRow(7)['question'], $this->storedRow(7)['recommendation'], $this->storedRow(7)['parked_at']]);
    }

    public function testAnotherTaskOfTheSameProjectIsParkedBesideTheFirst(): void
    {
        $this->parked()->park($this->request(7), $this->question('Which store?', 'SQLite'));

        $this->parked()->park($this->request(8), $this->question('Which queue?', 'Postgres'));

        self::assertSame(['Which store?', 'Which queue?'], [$this->storedRow(7)['question'], $this->storedRow(8)['question']]);
    }

    public function testATaskThatCannotBeStoredIsNotParkedAndTheFailureQuotesNothingOfTheQuestion(): void
    {
        $this->entities->getConnection()->executeStatement('DROP TABLE '.ParkedTaskRecord::TABLE);

        try {
            $this->parked()->park($this->request(7), $this->question('PLANT-question', 'SQLite'));
            self::fail('A task without its table was parked.');
        } catch (TaskNotParked $unparked) {
            self::assertSame('issue 7 of project "alpha" could not be parked', $unparked->getMessage());
            self::assertNotNull($unparked->getPrevious());
        }
    }

    private function parked(): ParkedTasks
    {
        return new ParkedTasks($this->entities->getConnection(), $this->clock);
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
