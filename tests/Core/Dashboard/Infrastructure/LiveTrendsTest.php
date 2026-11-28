<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\TrendsReading;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Dashboard\Infrastructure\LedgerQueuedRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTrends;
use Intakt\Core\Dashboard\Infrastructure\LiveTrends;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\UnpricedRoundCosts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[CoversClass(LiveTrends::class)]
#[CoversClass(LedgerTrends::class)]
#[CoversClass(TrendsReading::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerQueuedRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(PublishedBench::class)]
#[UsesClass(ReviewTrends::class)]
#[UsesClass(Board::class)]
#[UsesClass(UpNext::class)]
#[UsesClass(LiveEvent::class)]
final class LiveTrendsTest extends KernelTestCase
{
    private BufferingLogger $log;

    private InMemoryStore $locks;

    public function testTheTrendsArePushedOnTheShellTopicWithBothWaysOfCountingTheDemoAndUpNext(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRound('r-1', 'alpha');
        $live = new PublishedLiveEvents();

        $this->trendsOn($ledger, $live)->publishTheTrends();

        self::assertCount(1, $live->events);
        self::assertSame(DashboardTopics::SHELL, $live->events[0]->topic);
        self::assertSame(['trends-with-demo', 'trends-without-demo', 'up-next'], $this->targetsOf($live->events[0]));
    }

    public function testEveryPushedTargetIsMorphedSoAFocusedLinkKeepsItsNode(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRound('r-1', 'alpha');
        $live = new PublishedLiveEvents();

        $this->trendsOn($ledger, $live)->publishTheTrends();

        self::assertCount(0, new Crawler($live->events[0]->turboStream)->filter('turbo-stream:not([action="replace"][method="morph"])'));
    }

    // The empty state carries a form token, which a worker without a session cannot make.
    public function testBeforeAnyReviewOnlyUpNextIsPushedAndTheEmptyStateStaysAsLoaded(): void
    {
        $live = new PublishedLiveEvents();

        $this->trendsOn(new DashboardLedger(), $live)->publishLatest(Uuid::v7());

        self::assertSame(['up-next'], $this->targetsOf($live->events[0]));
        self::assertStringNotContainsString('_token', $live->events[0]->turboStream);
    }

    public function testAJudgedRoundPushesItsPassInTheTiles(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRound('r-1', 'alpha');
        $ledger->startRound('r-2', 'alpha');
        $ledger->judgeRound('r-1');
        $live = new PublishedLiveEvents();

        $this->trendsOn($ledger, $live)->publishLatest(Uuid::v7());

        self::assertSame('2', new Crawler($live->events[0]->turboStream)->filter('#trends-with-demo .tile[data-tile="passes"] dd')->first()->text());
    }

    public function testFiguresReadBeforeAnotherRoundStartedAreNeverTheLastPushed(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRound('r-1', 'alpha');
        $live = new PublishedLiveEvents();
        $meanwhile = new PublishingMeanwhile($live, static function () use ($ledger): void {
            $ledger->startRound('r-2', 'beta');
        });

        $this->trendsOn($ledger, $meanwhile)->publishLatest(Uuid::v7());

        self::assertCount(2, $live->events);
        self::assertSame('2', new Crawler($live->events[1]->turboStream)->filter('#trends-with-demo .tile[data-tile="passes"] dd')->first()->text());
    }

    public function testAnUnchangedLedgerIsPushedOnceForOneTrigger(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRound('r-1', 'alpha');
        $live = new PublishedLiveEvents();

        $this->trendsOn($ledger, $live)->publishLatest(Uuid::v7());

        self::assertCount(1, $live->events);
    }

    public function testAWorkerThatFindsTheLockTakenPushesNothingAndLeavesTheNewerFiguresToTheHolder(): void
    {
        $held = new LockFactory($this->locks)->createLock('dashboard-trends');
        self::assertTrue($held->acquire());
        $live = new PublishedLiveEvents();

        $this->trendsOn(new DashboardLedger(), $live)->publishTheTrends();

        self::assertSame([], $live->events);
        $held->release();
    }

    public function testALedgerThatCannotBeReadIsLoggedWithTheRoundAndNotThrown(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willThrowException(new RuntimeException('The ledger is gone.'));
        $round = Uuid::v7();

        $this->trendsOn($ledger, new PublishedLiveEvents())->publishLatest($round);

        $logs = $this->logged();
        self::assertCount(1, $logs);
        self::assertSame(['warning', 'The trends were not published.', $round->toRfc4122()], [$logs[0][0], $logs[0][1], $logs[0][2]['round'] ?? null]);
    }

    public function testALedgerThatCannotBeReadDuringAReplayIsLoggedWithoutARoundAndNotThrown(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willThrowException(new RuntimeException('The ledger is gone.'));

        $this->trendsOn($ledger, new PublishedLiveEvents())->publishTheTrends();

        $logs = $this->logged();
        self::assertCount(1, $logs);
        self::assertSame(['warning', 'The trends were not published.', false], [$logs[0][0], $logs[0][1], array_key_exists('round', $logs[0][2])]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new BufferingLogger();
        $this->locks = new InMemoryStore();
    }

    private function trendsOn(Ledger $ledger, LiveUpdates $live): LiveTrends
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $payloads = new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create()));
        $pages = new LedgerReviewPages(new MockClock(), $ledger, $payloads, new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        return new LiveTrends(new LedgerTrends($ledger, $pages, new LedgerQueuedRounds($payloads), new PublishedBench(''), new MockClock()), $twig, $live, new LockFactory($this->locks), $this->log);
    }

    /**
     * @return list<string>
     */
    private function targetsOf(LiveEvent $event): array
    {
        return new Crawler($event->turboStream)->filter('turbo-stream')->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));
    }

    /**
     * @return list<array{0: mixed, 1: mixed, 2: array<array-key, mixed>}>
     */
    private function logged(): array
    {
        return array_values(array_map(static fn (mixed $record): array => is_array($record) ? [$record[0] ?? null, $record[1] ?? null, is_array($record[2] ?? null) ? $record[2] : []] : [null, null, []], $this->log->cleanLogs()));
    }
}
