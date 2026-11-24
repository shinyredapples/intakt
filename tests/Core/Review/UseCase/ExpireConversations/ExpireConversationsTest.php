<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ExpireConversations;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\RetentionConfiguration;
use Intakt\Core\Review\Infrastructure\StoredConversations;
use Intakt\Core\Review\UseCase\ExpireConversations\ExpireConversations;
use Intakt\Core\Review\UseCase\ExpireConversations\ExpireConversationsHandler;
use Intakt\Core\Review\UseCase\ExpireConversations\RetentionSchedule;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[Group('persistence')]
#[CoversClass(ExpireConversationsHandler::class)]
#[CoversClass(StoredConversations::class)]
#[CoversClass(RetentionConfiguration::class)]
#[CoversClass(RetentionSchedule::class)]
#[UsesClass(ExpireConversations::class)]
final class ExpireConversationsTest extends KernelTestCase
{
    private const int DAY = 86400;

    private RoundFixture $fixture;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->executeStatement('CREATE TABLE reviewer_conversation (id VARCHAR(36) NOT NULL PRIMARY KEY, version VARCHAR(36) NOT NULL, messages CLOB NOT NULL, updated_at INTEGER NOT NULL)');
    }

    public function testAConversationOlderThanTheRetentionIsDeletedAndOneWithinItIsKept(): void
    {
        $this->conversation('old', 31);
        $this->conversation('recent', 29);

        $this->expire(30);

        self::assertSame(['recent'], $this->kept());
    }

    public function testAConversationOfARoundThatIsStillOpenIsKeptWhateverItsAge(): void
    {
        $round = $this->fixture->start(1);
        $reviewer = $round->reviewers()[0];
        $reviewer->recordStart($this->fixture->clock->now());
        $this->entities()->flush();
        $this->conversation($reviewer->conversationId()->toRfc4122(), 400);
        $this->conversation('finished', 400);

        $this->expire(30);

        self::assertSame([$reviewer->conversationId()->toRfc4122()], $this->kept());
    }

    public function testAConversationOfAJudgedRoundIsDeletedWhenOldEnough(): void
    {
        $round = $this->fixture->start(1);
        $reviewer = $round->reviewers()[0];
        $reviewer->recordStart($this->fixture->clock->now());
        $round->moveTo(RoundState::Judged->value);
        $this->entities()->flush();
        $this->conversation($reviewer->conversationId()->toRfc4122(), 40);

        $this->expire(30);

        self::assertSame([], $this->kept());
    }

    public function testKeepingForeverDeletesNothing(): void
    {
        $this->conversation('ancient', 5000);

        $this->expire(null);

        self::assertSame(['ancient'], $this->kept());
    }

    public function testTheTaskNeverDeletesOrChangesALedgerEvent(): void
    {
        $this->connection->executeStatement("INSERT INTO ledger_event (id, kind, payload, recorded_at, subject) VALUES ('11111111-1111-4111-8111-111111111111', 'reviewer_stopped', '{}', '2020-01-01 00:00:00', NULL)");
        $before = $this->connection->fetchAllAssociative('SELECT * FROM ledger_event');
        $this->conversation('old', 100);

        $this->expire(30);

        self::assertSame([], $this->kept());
        self::assertNotSame([], $before);
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM ledger_event'));
    }

    public function testTheScheduleRunsTheTaskEveryHour(): void
    {
        self::assertSame('1 hour', RetentionSchedule::INTERVAL);
        self::assertCount(1, new RetentionSchedule()->getSchedule()->getRecurringMessages());
    }

    #[DataProvider('settings')]
    public function testTheSettingIsDaysOrForever(mixed $value, ?int $days): void
    {
        $settings = new Processor()->processConfiguration(new RetentionConfiguration(), [['retention' => ['conversation_days' => $value]]]);

        self::assertSame($days, RetentionConfiguration::daysOf($settings['retention']['conversation_days']));
    }

    /**
     * @return iterable<string, array{mixed, ?int}>
     */
    public static function settings(): iterable
    {
        yield 'one day' => [1, 1];
        yield 'ninety days' => [90, 90];
        yield 'forever' => ['forever', null];
    }

    public function testTheDefaultIsThirtyDays(): void
    {
        $settings = new Processor()->processConfiguration(new RetentionConfiguration(), [[]]);

        self::assertSame(30, RetentionConfiguration::daysOf($settings['retention']['conversation_days']));
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new RetentionConfiguration(), [['retention' => ['conversation_days' => $value]]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
        yield 'text' => ['soon'];
        yield 'fraction' => [1.5];
    }

    private function expire(?int $days): void
    {
        new ExpireConversationsHandler(new StoredConversations($this->connection, $this->entities()), $this->fixture->clock, $days)(new ExpireConversations());
    }

    private function entities(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function conversation(string $id, int $daysOld): void
    {
        $this->connection->executeStatement('INSERT INTO reviewer_conversation (id, version, messages, updated_at) VALUES (?, ?, ?, ?)', [$id, 'v', '[]', $this->fixture->clock->now()->getTimestamp() - $daysOld * self::DAY - 60]);
    }

    /**
     * @return list<string>
     */
    private function kept(): array
    {
        return $this->connection->fetchFirstColumn('SELECT id FROM reviewer_conversation ORDER BY id');
    }
}
