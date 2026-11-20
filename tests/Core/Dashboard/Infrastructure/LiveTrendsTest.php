<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
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

    public function testAnInstallWithoutAReviewPushesOnlyUpNextSoThePagesStartFormStaysItsOwn(): void
    {
        $this->updates($this->ledger)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $streams = new Crawler($this->live->events[0]->turboStream);
        self::assertCount(1, $streams->filter('turbo-stream'));
        self::assertStringContainsString('Nothing running or queued.', $streams->filter('template')->html());
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
        $logs = $this->log->cleanLogs();
        self::assertSame('The trends were not published.', $logs[0][1] ?? null);
    }

    private function judgedRound(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');
        $this->ledger->judgeRound(self::ROUND);
    }

    private function updates(Ledger $ledger): TrendsRoundUpdates
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));
        $pages = new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        return new TrendsRoundUpdates($this->round, new LiveTrends($pages, $twig, $this->live, new LockFactory(new InMemoryStore()), $this->log));
    }
}
