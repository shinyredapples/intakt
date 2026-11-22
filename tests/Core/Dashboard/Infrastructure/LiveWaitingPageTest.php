<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\Elapsed;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveWaitingPage;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
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
use Twig\Environment;

#[CoversClass(LiveWaitingPage::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(WaitingPage::class)]
#[UsesClass(WaitingItem::class)]
#[UsesClass(Character::class)]
#[UsesClass(Elapsed::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(OwnerNoticesOfTheDay::class)]
#[UsesClass(Account::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(LiveEvent::class)]
final class LiveWaitingPageTest extends KernelTestCase
{
    /** @var list<OutageNotice> */
    private array $waiting = [];

    private PublishedLiveEvents $live;

    private BufferingLogger $log;

    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->live = new PublishedLiveEvents();
        $this->log = new BufferingLogger();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function testTheWaitingPageIsPublishedOnItsOwnTopic(): void
    {
        $this->waiting = [$this->noticeOf('main')];

        $this->pageOn($this->live)->publishTheWaitingPage();

        self::assertSame([DashboardTopics::WAITING], array_map(static fn (LiveEvent $event): string => $event->topic, $this->live->events));
    }

    public function testThePublishedPageReplacesTheHeadTheListAndThePanels(): void
    {
        $this->pageOn($this->live)->publishTheWaitingPage();

        $targets = new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[action="replace"][method="morph"]')
            ->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));

        self::assertSame(['waiting-head', 'waiting-list', 'waiting-side'], $targets);
    }

    public function testThePublishedListCarriesEachWaitingNotice(): void
    {
        $this->waiting = [$this->noticeOf('main'), $this->noticeOf('team')];

        $this->pageOn($this->live)->publishTheWaitingPage();

        $stream = new Crawler($this->live->events[0]->turboStream);
        self::assertCount(2, $stream->filter('turbo-stream[target="waiting-list"] template #waiting-list .wait-item'));
        self::assertSame('2 things need you', $stream->filter('turbo-stream[target="waiting-head"] template #waiting-head h1')->text());
    }

    public function testAPageThatFailsToPublishIsLoggedAsAWarningAndNeverThrown(): void
    {
        $failing = self::createStub(LiveUpdates::class);
        $failing->method('publish')->willThrowException(new RuntimeException('hub down'));

        $this->pageOn($failing)->publishTheWaitingPage();

        $logs = $this->log->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame(['warning', 'The Waiting on you page was not published.'], [$logs[0][0], $logs[0][1]]);
    }

    public function testAWorkerThatFindsThePageLockTakenLeavesThePageToItsHolder(): void
    {
        $held = $this->locks->createLock('dashboard-waiting-page');
        self::assertTrue($held->acquire(false));

        $this->pageOn($this->live)->publishTheWaitingPage();

        $held->release();
        self::assertSame([], $this->live->events);
    }

    // The second worker finds the lock taken and leaves, so the holder must read again after it lets go, or the notice opened meanwhile would never reach an open page.
    public function testANoticeOpenedWhileAnotherWorkerPublishesIsInTheLastPagePublished(): void
    {
        $secondWorker = $this->pageOn($this->live);
        $live = new PublishingMeanwhile($this->live, function () use ($secondWorker): void {
            $this->waiting = [$this->noticeOf('main')];
            $secondWorker->publishTheWaitingPage();
        });

        $this->pageOn($live)->publishTheWaitingPage();

        self::assertCount(2, $this->live->events);
        self::assertCount(0, new Crawler($this->live->events[0]->turboStream)->filter('.wait-item'));
        self::assertCount(1, new Crawler($this->live->events[1]->turboStream)->filter('.wait-item'));
    }

    private function pageOn(LiveUpdates $live): LiveWaitingPage
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $clock = new MockClock('2026-11-02 12:00:00 UTC');
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('waitingAndEndedOnTheDayOf')->willReturnCallback(fn (): OwnerNoticesOfTheDay => new OwnerNoticesOfTheDay($this->waiting, []));
        $pages = new LedgerReviewPages(new DashboardLedger(), new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        return new LiveWaitingPage(new WaitingPageReading($notices, $pages, $clock, []), $twig, $live, $this->locks, $clock, $this->log);
    }

    private function noticeOf(string $account): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, $account), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
    }
}
