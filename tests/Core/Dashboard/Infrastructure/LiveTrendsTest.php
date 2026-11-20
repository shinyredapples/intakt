<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Closure;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Dashboard\Domain\UpNextRound;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveTrends;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\Infrastructure\TrendsRoundUpdates;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[CoversClass(LiveTrends::class)]
#[CoversClass(TrendsRoundUpdates::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(UpNext::class)]
#[UsesClass(UpNextRound::class)]
#[UsesClass(ReviewTrends::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
final class LiveTrendsTest extends KernelTestCase
{
    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private DashboardLedger $ledger;

    private RecordedRoundUpdates $round;

    private PublishedLiveEvents $live;

    private BufferingLogger $log;

    /** @var list<string> */
    private array $replays = [];

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
        $this->round = new RecordedRoundUpdates();
        $this->live = new PublishedLiveEvents();
        $this->log = new BufferingLogger();
    }

    public function testAJudgedRoundPublishesTheFiguresAndUpNextOnTheShellTopic(): void
    {
        $this->judgedRound();

        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertSame([DashboardTopics::SHELL], array_map(static fn (LiveEvent $event): string => $event->topic, $this->live->events));
        $streams = new Crawler($this->live->events[0]->turboStream);
        self::assertCount(1, $streams->filter('turbo-stream[action="replace"][method="morph"][target="trends-up-next"]'));
        self::assertCount(1, $streams->filter('turbo-stream[target="trends-figures-with-demo"]'));
    }

    public function testThePushedFiguresCountTheNewlyJudgedRound(): void
    {
        $this->judgedRound();

        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $figures = new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[target="trends-figures-with-demo"] template');
        self::assertSame('1', trim($figures->filter('.tile dd')->first()->text()));
    }

    public function testThePushedFiguresKeepTheirOwnTargetForTheCountThatLeavesOutTheDemo(): void
    {
        $this->judgedRound();

        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertCount(1, new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[target="trends-figures-without-demo"]'));
    }

    public function testAnInstallWithoutAReviewPushesNoFiguresSoThePagesStartFormStaysItsOwn(): void
    {
        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $streams = new Crawler($this->live->events[0]->turboStream);
        self::assertCount(0, $streams->filter('turbo-stream[target^="trends-figures-"]'));
        self::assertStringNotContainsString('_token', $this->live->events[0]->turboStream);
        self::assertStringContainsString('Nothing running or queued.', $streams->filter('turbo-stream[target="trends-up-next"] template')->html());
    }

    public function testTheFirstJudgmentAlsoRefreshesTheHeadersRoundCount(): void
    {
        $this->judgedRound();

        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $head = new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[action="replace"][method="morph"][target="trends-head-with-demo"] template');
        self::assertStringContainsString('1 review round over every project', $head->html());
    }

    public function testADemoPlayedAfterTheTabOpenedPushesTheLinkThatLeavesItOut(): void
    {
        $this->ledger->startRoundOf('feat/demo', self::ROUND, 1);
        $this->ledger->judgeRound(self::ROUND);
        $this->replays = ['alpha'];

        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $head = new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[target="trends-head-with-demo"] template');
        self::assertStringContainsString('Includes the recorded demo review', $head->html());
        self::assertStringContainsString('Leave out the demo review', $head->html());
    }

    public function testAStoppedReviewerPublishesUpNextAgain(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');
        $this->ledger->stopReviewer(self::ROUND, 'security');

        $this->updates($this->ledger)->reviewerStopped(Uuid::fromString(self::ROUND), 'security');

        $item = new Crawler($this->live->events[0]->turboStream)->filter('#trends-up-next li');
        self::assertStringContainsString('in progress, no reviewer running', $item->text());
    }

    public function testAWorkerThatMissesTheLockLeavesItsChangeToTheHolderWhichPublishesItAfterReadingAgain(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $locks = new LockFactory(new InMemoryStore());
        $second = null;
        $updates = new class($this->live, function () use (&$second): void {
            $this->ledger->startReviewer(self::ROUND, 'security');
            self::assertInstanceOf(LiveTrends::class, $second);
            $second->publishLatest(Uuid::fromString(self::ROUND));
        }) implements LiveUpdates {
            private bool $interleaved = false;

            public function __construct(private readonly PublishedLiveEvents $live, private readonly Closure $whilePublishing)
            {
            }

            #[Override]
            public function publish(LiveEvent $event): void
            {
                $this->live->publish($event);

                if (!$this->interleaved) {
                    $this->interleaved = true;
                    ($this->whilePublishing)();
                }
            }
        };
        $second = $this->liveTrends($this->ledger, $this->live, $locks);

        $this->liveTrends($this->ledger, $updates, $locks)->publishLatest(Uuid::fromString(self::ROUND));

        self::assertCount(2, $this->live->events);
        self::assertStringContainsString('waiting to start', $this->live->events[0]->turboStream);
        self::assertStringContainsString('running: security', $this->live->events[1]->turboStream);
    }

    public function testAWorkerThatMissesTheLockPublishesNothingItself(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock('dashboard-trends');
        self::assertTrue($held->acquire(false));

        $this->liveTrends($this->ledger, $this->live, $locks)->publishLatest(Uuid::fromString(self::ROUND));

        self::assertSame([], $this->live->events);
    }

    public function testAStartedReviewerListsItsRoundAsRunningInUpNext(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->updates($this->ledger)->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $item = new Crawler($this->live->events[0]->turboStream)->filter('#trends-up-next li');
        self::assertCount(1, $item);
        self::assertStringContainsString('feat/a', $item->text());
        self::assertStringContainsString('running: security', $item->text());
        self::assertSame('/reviews/'.self::ROUND, $item->filter('a')->attr('href'));
    }

    public function testToolCallsAndTextsReachOnlyTheRoundsOwnUpdates(): void
    {
        $this->updates($this->ledger)->toolCalled(Uuid::fromString(self::ROUND), 'security', 'read_file');

        self::assertSame(['tool security read_file'], $this->round->seen);
        self::assertSame([], $this->live->events);
    }

    public function testAHubFailureWhilePublishingIsLoggedAndDoesNotFailTheRoundUpdate(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willThrowException(new RuntimeException('database is locked'));

        $this->updates($ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertSame([], $this->live->events);
        $messages = array_map(static fn (mixed $record): mixed => is_array($record) ? ($record[1] ?? null) : null, $this->log->cleanLogs());
        self::assertSame(['The trends were not published.'], $messages);
    }

    private function judgedRound(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');
        $this->ledger->judgeRound(self::ROUND);
    }

    private function updates(Ledger $ledger): TrendsRoundUpdates
    {
        return new TrendsRoundUpdates($this->round, $this->liveTrends($ledger, $this->live, new LockFactory(new InMemoryStore())));
    }

    private function liveTrends(Ledger $ledger, LiveUpdates $updates, LockFactory $locks): LiveTrends
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));
        $pages = new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus($this->replays)));

        return new LiveTrends($pages, $twig, $updates, $locks, $this->log);
    }
}
