<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\MeanwhileLine;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveWaitingPage;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageRoundUpdates;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\UnpricedRoundCosts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[CoversClass(WaitingPageRoundUpdates::class)]
#[UsesClass(LiveWaitingPage::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(WaitingPage::class)]
#[UsesClass(MeanwhileLine::class)]
#[UsesClass(OwnerNoticesOfTheDay::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(ReadAnswer::class)]
final class WaitingPageRoundUpdatesTest extends KernelTestCase
{
    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private DashboardLedger $ledger;

    private RecordedRoundUpdates $round;

    private PublishedLiveEvents $live;

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
        $this->round = new RecordedRoundUpdates();
        $this->live = new PublishedLiveEvents();
    }

    public function testAnOpenedRoundPublishesTheWaitingPageWithItsMeanwhileLine(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        self::assertSame([DashboardTopics::WAITING], array_map(static fn (LiveEvent $event): string => $event->topic, $this->live->events));
        self::assertSame(['feat/a of alpha is in review, round 1'], new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[target="waiting-side"] template li a')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testAJudgedRoundPublishesAWaitingPageWithoutItsMeanwhileLine(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->judgeRound(self::ROUND);

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertSame('No review is running now.', new Crawler($this->live->events[0]->turboStream)->filter('turbo-stream[target="waiting-side"] template p.none')->first()->text());
    }

    public function testAChangedRoundReachesTheRoundsOwnUpdatesBeforeThePageIsPublished(): void
    {
        $publishedBefore = null;
        $this->round->whilePublishingNextRoundChange = function () use (&$publishedBefore): void {
            $publishedBefore = count($this->live->events);
        };

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame(0, $publishedBefore);
        self::assertCount(1, $this->live->events);
    }

    public function testEveryOtherRoundUpdateReachesOnlyTheRoundsOwnUpdates(): void
    {
        $round = Uuid::fromString(self::ROUND);
        $updates = $this->updates();

        $updates->toolCalled($round, 'reviewer-1', 'read_file');
        $updates->textStreamed($round, 'reviewer-1', 'Reading.');
        $updates->reviewerAskedAgain($round, 'reviewer-1', 'try-2');
        $updates->summaryChanged($round);
        $updates->reviewerStopped($round, 'reviewer-1');
        $updates->answerRead($round, 'reviewer-1', ReadAnswer::unreadable());
        $updates->reviewerStarted($round, 'reviewer-1');

        self::assertSame(['tool reviewer-1 read_file', 'text reviewer-1 Reading.', 'asked again reviewer-1', 'summary '.self::ROUND, 'stopped reviewer-1', 'answer reviewer-1 unreadable'], $this->round->seen);
        self::assertSame(['reviewer-1'], $this->round->started);
        self::assertSame([], $this->live->events);
    }

    private function updates(): WaitingPageRoundUpdates
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $clock = new MockClock('2026-10-28 12:00:00 UTC');
        $notices = self::createStub(OwnerNotices::class);
        $notices->method('waitingAndEndedOnTheDayOf')->willReturn(new OwnerNoticesOfTheDay([], []));
        $pages = new LedgerReviewPages($this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));
        $page = new LiveWaitingPage(new WaitingPageReading($notices, $pages, $clock, []), $twig, $this->live, new LockFactory(new InMemoryStore()), $clock, new NullLogger());

        return new WaitingPageRoundUpdates($this->round, $page);
    }
}
