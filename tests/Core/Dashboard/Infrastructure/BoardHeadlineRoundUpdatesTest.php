<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\BoardHeadlineRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[CoversClass(BoardHeadlineRoundUpdates::class)]
#[CoversClass(LiveBoardHeadline::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(ReadAnswer::class)]
final class BoardHeadlineRoundUpdatesTest extends KernelTestCase
{
    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private const string STREAM_START = '<turbo-stream action="update" method="morph" target="board-headline"><template>';

    private DashboardLedger $ledger;

    private RecordedRoundUpdates $round;

    private PublishedLiveEvents $live;

    private BufferingLogger $log;

    private LockFactory $locks;

    private ArrayAdapter $lastPushed;

    public function testAStartedReviewerPublishesTheBoardHeadlineOnTheShellTopic(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->updates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        self::assertSame([DashboardTopics::SHELL], array_map(static fn (LiveEvent $event): string => $event->topic, $this->live->events));
        self::assertSame('The Code reviewers are reviewing change feat/a of alpha. The Code reviewers are reviewing 1 change.', $this->headlineIn(0));
    }

    public function testThePublishedHeadlineUpdatesTheBoardHeadlineTarget(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->updates()->reviewerStarted(Uuid::fromString(self::ROUND), 'reviewer-1');

        self::assertStringStartsWith(self::STREAM_START, $this->live->events[0]->turboStream);
    }

    public function testAStartedReviewerReachesTheRoundsOwnUpdatesToo(): void
    {
        $this->updates()->reviewerStarted(Uuid::fromString(self::ROUND), 'reviewer-1');

        self::assertSame(['reviewer-1'], $this->round->started);
    }

    public function testAJudgedRoundPublishesAHeadlineWithoutItsReviewers(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');
        $this->ledger->judgeRound(self::ROUND);

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertSame('Nothing is running.', $this->headlineIn(0));
    }

    public function testAChangedRoundPublishesTheHeadlineAfterTheRoundsOwnUpdate(): void
    {
        $publishedBefore = null;
        $this->round->whilePublishingNextRoundChange = function () use (&$publishedBefore): void {
            $publishedBefore = count($this->live->events);
        };

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame(0, $publishedBefore);
        self::assertCount(1, $this->live->events);
    }

    public function testAFreshInstallPublishesTheFirstReviewHeadline(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        self::assertSame('Nothing is running yet. Start the first review.', $this->headlineIn(0));
    }

    public function testToolCallsTextRetriesSummariesAndStopsReachOnlyTheRoundsOwnUpdates(): void
    {
        $round = Uuid::fromString(self::ROUND);
        $updates = $this->updates();

        $updates->toolCalled($round, 'reviewer-1', 'read_file');
        $updates->textStreamed($round, 'reviewer-1', 'Reading.');
        $updates->reviewerAskedAgain($round, 'reviewer-1', 'try-2');
        $updates->summaryChanged($round);
        $updates->reviewerStopped($round, 'reviewer-1');

        self::assertSame(['tool reviewer-1 read_file', 'text reviewer-1 Reading.', 'asked again reviewer-1', 'summary '.self::ROUND, 'stopped reviewer-1'], $this->round->seen);
        self::assertSame([], $this->live->events);
    }

    public function testAReadAnswerIsForwardedToTheRoundUpdatesAndPublishesNoHeadline(): void
    {
        $this->updates()->answerRead(Uuid::fromString(self::ROUND), 'reviewer-1', ReadAnswer::unreadable());

        self::assertSame(['answer reviewer-1 unreadable'], $this->round->seen);
        self::assertSame([], $this->live->events);
    }

    public function testAProjectNameIsEscapedInThePublishedHeadline(): void
    {
        $this->ledger->startRound(self::ROUND, '</template><b>x</b>');
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->updates()->reviewerStarted(Uuid::fromString(self::ROUND), 'reviewer-1');

        self::assertStringContainsString('of &lt;/template&gt;&lt;b&gt;x&lt;/b&gt;.', $this->live->events[0]->turboStream);
        $headline = new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[target="board-headline"]');
        self::assertCount(1, $headline);
        self::assertSame(0, $headline->filter('b')->count());
    }

    public function testALedgerThatCannotBeReadPublishesNothingAndIsLoggedWithTheRound(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willThrowException(new RuntimeException('database is locked'));

        new BoardHeadlineRoundUpdates($this->round, $this->headline($ledger, $this->live))->reviewerStarted(Uuid::fromString(self::ROUND), 'reviewer-1');

        self::assertSame([], $this->live->events);
        self::assertSame([['warning', 'The board headline was not published.', self::ROUND, RuntimeException::class]], $this->loggedWarnings());
    }

    public function testAHeadlineReadBeforeAnotherReviewerStartedIsNeverTheLastOnePublished(): void
    {
        $other = '0199a5b4-0000-7000-8000-000000000002';
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');
        $secondWorker = $this->headline($this->ledger, $this->live);
        $live = new PublishingMeanwhile($this->live, function () use ($other, $secondWorker): void {
            $this->ledger->startRoundOf('feat/b', $other, 1);
            $this->ledger->startReviewer($other, 'security');
            $secondWorker->publishLatest(Uuid::fromString($other));
        });

        new BoardHeadlineRoundUpdates($this->round, $this->headline($this->ledger, $live))->reviewerStarted(Uuid::fromString(self::ROUND), 'reviewer-1');

        self::assertCount(2, $this->live->events);
        self::assertSame('The Code reviewers are reviewing change feat/a of alpha and change feat/b of alpha. The Code reviewers are reviewing 2 changes.', $this->headlineIn(1));
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
        $this->round = new RecordedRoundUpdates();
        $this->live = new PublishedLiveEvents();
        $this->log = new BufferingLogger();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->lastPushed = new ArrayAdapter();
    }

    private function updates(): BoardHeadlineRoundUpdates
    {
        return new BoardHeadlineRoundUpdates($this->round, $this->headline($this->ledger, $this->live));
    }

    private function headline(Ledger $ledger, LiveUpdates $live): LiveBoardHeadline
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new LiveBoardHeadline($this->pagesOn($ledger), $twig, $live, $this->locks, $this->lastPushed, $this->log);
    }

    private function pagesOn(Ledger $ledger): LedgerReviewPages
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));

        return new LedgerReviewPages(new MockClock(),$ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));
    }

    /**
     * @return list<array{mixed, mixed, mixed, string|null}>
     */
    private function loggedWarnings(): array
    {
        return array_values(array_map(static function (mixed $record): array {
            $record = is_array($record) ? $record : [];
            $context = is_array($record[2] ?? null) ? $record[2] : [];
            $exception = $context['exception'] ?? null;

            return [$record[0] ?? null, $record[1] ?? null, $context['round'] ?? null, is_object($exception) ? $exception::class : null];
        }, $this->log->cleanLogs()));
    }

    private function headlineIn(int $event): string
    {
        $stream = $this->live->events[$event]->turboStream;
        self::assertStringStartsWith(self::STREAM_START, $stream);

        $headline = new Crawler($stream)->filter('turbo-stream[target="board-headline"]');

        return trim(html_entity_decode(strip_tags($headline->html())));
    }
}
