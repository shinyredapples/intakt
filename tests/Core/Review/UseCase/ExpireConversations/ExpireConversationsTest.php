<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ExpireConversations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261029120000;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\RetentionConfiguration;
use Intakt\Core\Review\Infrastructure\StoredConversations;
use Intakt\Core\Review\UseCase\ExpireConversations\ExpireConversations;
use Intakt\Core\Review\UseCase\ExpireConversations\ExpireConversationsHandler;
use Intakt\Core\Review\UseCase\ExpireConversations\RetentionSchedule;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudging;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(ExpireConversationsHandler::class)]
#[CoversClass(StoredConversations::class)]
#[CoversClass(RetentionConfiguration::class)]
#[CoversClass(RetentionSchedule::class)]
#[UsesClass(ExpireConversations::class)]
final class ExpireConversationsTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private Connection $connection;

    public function testAConversationOlderThanTheRetentionIsDeletedAndOneWithinItIsKept(): void
    {
        $this->conversation($this->idStartedDaysAgo(31), 31);
        $recent = $this->conversation($this->idStartedDaysAgo(29), 29);

        $this->expire(30);

        self::assertSame([$recent], $this->kept());
    }

    public function testAConversationOfARoundThatIsStillOpenIsKeptWhateverItsAge(): void
    {
        $open = $this->startedReviewer();
        $this->conversation($open, 400);

        $this->expire(30);

        self::assertSame([$open], $this->kept());
    }

    public function testAConversationOfAJudgedRoundIsDeletedWhenOldEnough(): void
    {
        $this->fixture->clock->modify('-40 days');
        $judged = $this->startedReviewer(RoundState::Judged);
        $this->fixture->clock->modify('+40 days');
        $this->conversation($judged, 40);

        $this->expire(30);

        self::assertSame([], $this->kept());
    }

    // A failed judging moves no state, so the round stays complete for good and must count as finished.
    public function testAConversationOfARoundWhoseJudgingFailedIsDeletedWhenOld(): void
    {
        $round = $this->fixture->start(1);
        $reviewer = $round->reviewers()[0];
        $reviewer->recordStart($this->fixture->clock->now());
        $round->moveTo(RoundState::Complete->value);
        $this->entities()->flush();
        $this->fixture->failedJudgingRecorder($this->commands(), new NullLogger())(new RecordFailedJudging($round->id()->toRfc4122()));
        $this->conversation($reviewer->conversationId()->toRfc4122(), 400);

        $this->expire(30);

        self::assertSame([], $this->kept());
    }

    public function testAConversationOfACompleteRoundWaitingForItsJudgingIsKept(): void
    {
        $waiting = $this->startedReviewer(RoundState::Complete);
        $this->conversation($waiting, 400);

        $this->expire(30);

        self::assertSame([$waiting], $this->kept());
    }

    // A retry gives the reviewer a new conversation id, so the round no longer names the earlier one.
    public function testAnEarlierAttemptOfAReviewerIsKeptWhileItsRoundIsOpen(): void
    {
        $reviewer = $this->fixture->start(1)->reviewers()[0];
        $reviewer->recordStart($this->fixture->clock->now());
        $this->entities()->flush();
        $earlier = $this->conversation($reviewer->conversationId()->toRfc4122(), 40);
        $this->fixture->clock->modify('+30 minutes');
        $reviewer->recordRestart();
        $reviewer->recordStart($this->fixture->clock->now());
        $this->entities()->flush();
        $this->conversation($reviewer->conversationId()->toRfc4122(), 0);

        $this->expire(30);

        self::assertContains($earlier, $this->kept());
    }

    public function testAConversationStartedBeforeTheOldestOpenRoundIsDeletedWhenOld(): void
    {
        $before = $this->conversation($this->idStartedDaysAgo(50), 50);
        $open = $this->startedReviewer();
        $this->conversation($open, 40);

        $this->expire(30);

        self::assertSame([$open], $this->kept());
        self::assertNotContains($before, $this->kept());
    }

    // The review gives a round up at the same deadline, so a crashed reviewer's round cannot keep later reviews' code for good.
    public function testARoundStuckLongPastItsDeadlineDoesNotKeepEveryLaterConversation(): void
    {
        $this->fixture->clock->modify('-365 days');
        $this->startedReviewer();
        $this->fixture->clock->modify('+265 days');
        $judged = $this->startedReviewer(RoundState::Judged);
        $this->fixture->clock->modify('+100 days');
        $this->conversation($judged, 100);

        $this->expire(30);

        self::assertSame([], $this->kept());
    }

    public function testAnExpiredJudgedConversationIsDeletedWhileAnOlderRoundIsStillCollecting(): void
    {
        $this->fixture->clock->modify('-32 days');
        $this->startedReviewer();
        $this->fixture->clock->modify('+1 day');
        $judged = $this->startedReviewer(RoundState::Judged);
        $this->fixture->clock->modify('+31 days');
        $this->conversation($judged, 31);

        $this->expire(30);

        self::assertSame([], $this->kept());
    }

    // One reviewer and the judge get an hour each, so the deadline falls two hours after the start.
    public function testARoundCountsAsOpenUntilItsNoOutcomeDeadline(): void
    {
        $this->fixture->clock->modify('-7199 seconds');
        $open = $this->startedReviewer();
        $this->fixture->clock->modify('+7199 seconds');
        $this->conversation($open, 40);

        $this->expire(30);

        self::assertSame([$open], $this->kept());
    }

    public function testARoundAtItsNoOutcomeDeadlineCountsAsFinished(): void
    {
        $this->fixture->clock->modify('-7200 seconds');
        $ended = $this->startedReviewer();
        $this->fixture->clock->modify('+7200 seconds');
        $this->conversation($ended, 40);

        $this->expire(30);

        self::assertSame([], $this->kept());
    }

    // A round carries its whole diff, so loading the rounds would keep every pending diff in memory for each run.
    public function testTheTaskLoadsNoRoundIntoTheEntityManager(): void
    {
        $this->fixture->clock->modify('-90 days');
        $this->startedReviewer();
        $this->startedReviewer(RoundState::Complete);
        $this->fixture->clock->modify('+90 days');
        $this->startedReviewer();
        $this->entities()->clear();

        $this->expire(30);

        self::assertCount(0, $this->entities()->getUnitOfWork()->getIdentityMap()[ReviewRound::class] ?? []);
    }

    public function testKeepingForeverDeletesNothing(): void
    {
        $ancient = $this->conversation($this->idStartedDaysAgo(5000), 5000);

        $this->expire(null);

        self::assertSame([$ancient], $this->kept());
    }

    public function testTheTaskNeverDeletesOrChangesALedgerEvent(): void
    {
        $this->connection->insert(
            'ledger_event',
            ['id' => new UuidV7(), 'kind' => 'reviewer_stopped', 'payload' => '{}', 'recorded_at' => new DateTimeImmutable('2020-01-01 00:00:00'), 'subject' => null],
            ['id' => UuidType::NAME, 'recorded_at' => Types::DATETIME_IMMUTABLE],
        );
        $before = $this->connection->fetchAllAssociative('SELECT * FROM ledger_event');
        $this->conversation($this->idStartedDaysAgo(100), 100);

        $this->expire(30);

        self::assertSame([], $this->kept());
        self::assertNotSame([], $before);
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM ledger_event'));
    }

    public function testTheScheduleRunsTheTaskEveryTenMinutes(): void
    {
        self::assertSame('10 minutes', RetentionSchedule::INTERVAL);
        self::assertCount(1, new RetentionSchedule()->getSchedule()->getRecurringMessages());
    }

    // Each start of the consumer starts the schedule afresh, so a first run due after the consumer's time limit would never come.
    public function testTheFirstExpiryIsDueWellBeforeTheImageRecyclesTheSchedulerConsumer(): void
    {
        $entrypoint = (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/docker/entrypoint.sh');
        self::assertSame(1, preg_match('/messenger:consume scheduler_watcher scheduler_retention .*--time-limit=(\d+)/', $entrypoint, $match));
        $timeLimit = (int) ($match[1] ?? 0);
        $start = new DateTimeImmutable('2026-11-24 10:20:20');

        $firstDue = new RetentionSchedule()->getSchedule()->getRecurringMessages()[0]->getTrigger()->getNextRunDate($start);

        self::assertNotNull($firstDue);
        self::assertLessThanOrEqual(intdiv($timeLimit, 2), $firstDue->getTimestamp() - $start->getTimestamp());
    }

    #[DataProvider('settings')]
    public function testTheSettingIsDaysOrForever(mixed $value, ?int $days): void
    {
        self::assertSame($days, $this->daysSetBy(['retention' => ['conversation_days' => $value]]));
    }

    /**
     * @return iterable<string, array{mixed, ?int}>
     */
    public static function settings(): iterable
    {
        yield 'one day' => [1, 1];
        yield 'ninety days' => [90, 90];
        yield 'a hundred years' => [36500, 36500];
        yield 'forever' => ['forever', null];
    }

    public function testTheDefaultIsThirtyDays(): void
    {
        self::assertSame(30, $this->daysSetBy([]));
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
        yield 'a day over a hundred years' => [36501];
        // So large that subtracting it from now overflows into a cutoff far in the future, which would delete every conversation.
        yield 'the largest integer' => [PHP_INT_MAX];
    }

    // From the migration, because the test schema is built from the ORM mapping and the message store's table has no entity.
    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $migration = new Version20261029120000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function startedReviewer(?RoundState $state = null): string
    {
        $round = $this->fixture->start(1);
        $reviewer = $round->reviewers()[0];
        $reviewer->recordStart($this->fixture->clock->now());

        if ($state instanceof RoundState) {
            $round->moveTo($state->value);
        }

        $this->entities()->flush();

        return $reviewer->conversationId()->toRfc4122();
    }

    private function idStartedDaysAgo(int $days): string
    {
        return new UuidV7(UuidV7::generate($this->fixture->clock->now()->modify(sprintf('-%d days', $days))))->toRfc4122();
    }

    private function conversation(string $id, int $daysOld): string
    {
        $this->connection->insert('reviewer_conversation', ['id' => $id, 'version' => 'v', 'messages' => '[]', 'updated_at' => $this->fixture->clock->now()->modify(sprintf('-%d days -1 minute', $daysOld))->getTimestamp()]);

        return $id;
    }

    private function expire(?int $days): void
    {
        $stored = new StoredConversations($this->connection, $this->entities(), $this->fixture->noFailedJudgings(), $this->fixture->clock, new UpperWait());

        new ExpireConversationsHandler($stored, $this->fixture->clock, $days)();
    }

    private function entities(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function commands(): MessageBusInterface
    {
        return self::getContainer()->get('command.bus');
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function daysSetBy(array $configuration): ?int
    {
        /** @var array{retention: array{conversation_days: int|string}} $settings */
        $settings = new Processor()->processConfiguration(new RetentionConfiguration(), [$configuration]);

        return RetentionConfiguration::daysOf($settings['retention']['conversation_days']);
    }

    /**
     * @return list<string>
     */
    private function kept(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM reviewer_conversation ORDER BY id');

        return $ids;
    }
}
