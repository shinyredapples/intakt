<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Elapsed;
use Intakt\Core\Dashboard\Domain\HandledLine;
use Intakt\Core\Dashboard\Domain\MeanwhileLine;
use Intakt\Core\Dashboard\Domain\WaitingChoice;
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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Twig\Environment;

#[CoversClass(LiveWaitingPage::class)]
#[CoversClass(WaitingPageReading::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(WaitingPage::class)]
#[UsesClass(WaitingItem::class)]
#[UsesClass(WaitingChoice::class)]
#[UsesClass(MeanwhileLine::class)]
#[UsesClass(HandledLine::class)]
#[UsesClass(Elapsed::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(EndedOutageNotice::class)]
#[UsesClass(Account::class)]
#[UsesClass(LiveEvent::class)]
final class LiveWaitingPageTest extends KernelTestCase
{
    private ChangingOwnerNotices $notices;
    private DashboardLedger $ledger;
    private LockFactory $locks;
    private MockClock $clock;

    #[Override]
    protected function setUp(): void
    {
        $this->notices = new ChangingOwnerNotices();
        $this->ledger = new DashboardLedger();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->clock = new MockClock('2026-11-02 12:00:00 UTC');
    }

    public function testThePagePublishedOnTheWaitingTopicCarriesItsLeadItsListAndItsPanels(): void
    {
        $this->notices->running = [$this->outage('main')];
        $live = new PublishedLiveEvents();

        $this->pageOn($live)->publishLatest();

        self::assertCount(1, $live->events);
        self::assertSame(DashboardTopics::WAITING, $live->events[0]->topic);
        $streams = new Crawler($live->events[0]->turboStream);
        self::assertSame('One thing only you can do, oldest first. Everything else keeps running while it waits.', $streams->filter('turbo-stream[action="update"][target="waiting-lead"] template')->text());
        self::assertCount(1, $streams->filter('turbo-stream[action="replace"][target="waiting-list"] template #waiting-list .wait-item'));
        self::assertCount(1, $streams->filter('turbo-stream[action="replace"][target="waiting-side"] template aside#waiting-side'));
    }

    public function testThePushedPageListsTheReviewRunningNowUnderMeanwhile(): void
    {
        $this->ledger->startRoundOf('feat/a', 'r1', 2);
        $live = new PublishedLiveEvents();

        $this->pageOn($live)->publishLatest();

        self::assertSame('feat/a of alpha is in review, round 2', new Crawler($live->events[0]->turboStream)->filter('#waiting-side li a[href="/reviews/r1"]')->text());
    }

    public function testThePushedPageListsTheOutageEndedTodayUnderYouHandledToday(): void
    {
        $this->notices->ended = [new EndedOutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T09:14:00+00:00'))];
        $live = new PublishedLiveEvents();

        $this->pageOn($live)->publishLatest();

        $line = new Crawler($live->events[0]->turboStream)->filter('#waiting-side li');
        self::assertSame('Readings of account claude_code.main came back09:14 UTC', $line->text());
        self::assertSame('2026-11-02T09:14:00Z', $line->filter('time')->attr('datetime'));
    }

    // Today is read from the clock, so a page pushed after midnight UTC asks for the new day.
    public function testThePushedPageAsksForTheOutagesEndedOnTheClocksDay(): void
    {
        $this->pageOn(new PublishedLiveEvents())->publishLatest();

        $days = array_unique(array_map(static fn (DateTimeImmutable $moment): string => $moment->format(DATE_ATOM), $this->notices->daysAsked));

        self::assertSame(['2026-11-02T12:00:00+00:00'], $days);
    }

    // Read again after the lock is let go, and the same page is not sent twice in one call.
    public function testAPageThatDidNotChangeWhileItWasPublishedIsPublishedOnce(): void
    {
        $this->notices->running = [$this->outage('main')];
        $live = new PublishedLiveEvents();

        $this->pageOn($live)->publishLatest();

        self::assertCount(1, $live->events);
    }

    public function testAFailedReadIsLoggedAsAWarningAndDoesNotFailTheCaller(): void
    {
        $this->notices->failure = new RuntimeException('The notices could not be read.');
        $live = new PublishedLiveEvents();
        $log = new BufferingLogger();

        $this->pageOn($live, $log)->publishLatest();

        self::assertSame([], $live->events);
        self::assertSame([['warning', 'The waiting page was not published.']], array_map(static fn (array $record): array => [$record[0], $record[1]], $log->cleanLogs()));
    }

    // The holder publishes while the other worker finds its lock taken, so only the holder's read after letting go can carry the outage that ended meanwhile.
    public function testAPublisherThatFindsTheLockHeldLeavesThePublishToTheHolderWhichReadsAgain(): void
    {
        $this->notices->running = [$this->outage('main')];
        $published = new PublishedLiveEvents();
        $other = $this->pageOn($published);
        $notices = $this->notices;
        $live = new class($published, $notices, $other) implements LiveUpdates {
            public function __construct(private readonly PublishedLiveEvents $published, private readonly ChangingOwnerNotices $notices, private readonly LiveWaitingPage $other)
            {
            }

            #[Override]
            public function publish(LiveEvent $event): void
            {
                $this->published->publish($event);
                if (1 === count($this->published->events)) {
                    $this->notices->running = [];
                    $this->other->publishLatest();
                }
            }
        };

        $this->pageOn($live)->publishLatest();

        self::assertCount(2, $published->events);
        self::assertCount(1, new Crawler($published->events[1]->turboStream)->filter('#waiting-list .empty'));
    }

    private function pageOn(LiveUpdates $live, ?BufferingLogger $log = null): LiveWaitingPage
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $pages = new LedgerReviewPages($this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        return new LiveWaitingPage(new WaitingPageReading($this->notices, $pages, $this->clock, []), $twig, $live, $this->locks, $this->clock, $log ?? new NullLogger());
    }

    private function outage(string $account): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, $account), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
    }
}
