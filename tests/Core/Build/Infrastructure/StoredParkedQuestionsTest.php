<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Intakt\Core\Build\Contract\ParkedQuestion;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Build\Infrastructure\StoredParkedQuestions;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Tests\Core\Build\ParkedTaskTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoredParkedQuestions::class)]
#[CoversClass(ParkedQuestion::class)]
#[UsesClass(ParkedTaskRecord::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class StoredParkedQuestionsTest extends TestCase
{
    private const string RUN = '3c9a7b52-1e4d-4f6a-8b2c-5d7e9f0a1b3c';

    private Connection $connection;

    public function testAParkedTaskGivesItsQuestionChoicesRecommendationAndTime(): void
    {
        $this->park('alpha', 7, '{"question":"Keep the old flag?","choices":["keep it","drop it"],"recommendation":"drop it"}');

        $question = new StoredParkedQuestions($this->connection)->of('alpha', 7);

        self::assertEquals(new ParkedQuestion('Keep the old flag?', ['keep it', 'drop it'], 'drop it', new DateTimeImmutable('2026-11-20 10:00:00'), self::RUN), $question);
    }

    public function testATaskThatIsNotParkedGivesNoQuestion(): void
    {
        $this->park('alpha', 7, '{"question":"Keep the old flag?","choices":["a","b"],"recommendation":"a"}');

        $questions = new StoredParkedQuestions($this->connection);

        self::assertSame([null, null], [$questions->of('alpha', 8), $questions->of('beta', 7)]);
    }

    /**
     * @return iterable<string, array{array<string, ?string>}>
     */
    public static function malformedRows(): iterable
    {
        yield 'a question that is no JSON' => [['question' => 'Keep?']];
        yield 'a question that is no text' => [['question' => '42']];
        yield 'no choices' => [['choices' => '[]']];
        yield 'choices that are no list' => [['choices' => '{"a":"b"}']];
        yield 'choices that are an object with a list\'s keys' => [['choices' => '{"0":"a","1":"b"}']];
        yield 'choices that are no JSON' => [['choices' => 'a, b']];
        yield 'a choice that is empty' => [['choices' => '["a",""]']];
        yield 'a choice that is only spaces' => [['choices' => '["a"," "]']];
        yield 'a choice that is not trimmed' => [['choices' => '["a"," b"]']];
        yield 'choices that name one twice' => [['choices' => '["a","a"]']];
        yield 'a question that is empty' => [['question' => '""']];
        yield 'a question that is not trimmed' => [['question' => '" Keep?"']];
        yield 'a recommendation that is empty' => [['recommendation' => '""']];
        yield 'a recommendation that is not one of the choices' => [['recommendation' => '"c"']];
        yield 'no run' => [['run' => null]];
        yield 'a run that is no run id' => [['run' => 'run-1']];
        yield 'a choice that is no text' => [['choices' => '["a",1]']];
        yield 'a recommendation that is no text' => [['recommendation' => 'null']];
        yield 'a time that is no time' => [['parked_at' => 'yesterday-ish']];
    }

    /**
     * @param array<string, ?string> $broken
     */
    #[DataProvider('malformedRows')]
    public function testARowOutsideTheShapeTheParkingWritesGivesNoQuestion(array $broken): void
    {
        $this->park('alpha', 7, '{"question":"Keep?","choices":["a","b"],"recommendation":"a"}');
        $this->connection->update(ParkedTaskRecord::TABLE, $broken, ['project' => 'alpha']);

        self::assertNull(new StoredParkedQuestions($this->connection)->of('alpha', 7));
    }

    protected function setUp(): void
    {
        $this->connection = ParkedTaskTable::inMemory();
    }

    private function park(string $project, int $task, string $question): void
    {
        $record = new ParkedTaskRecord($project, $task, BuildQuestion::fromHandedBack($question), new DateTimeImmutable('2026-11-20 10:00:00'), self::RUN);
        $this->connection->insert(ParkedTaskRecord::TABLE, $record->row(), ParkedTaskRecord::rowTypes());
    }
}
