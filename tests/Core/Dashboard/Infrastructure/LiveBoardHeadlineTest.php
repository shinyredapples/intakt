<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use ArrayObject;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\UnpricedRoundCosts;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[CoversClass(LiveBoardHeadline::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(LiveEvent::class)]
final class LiveBoardHeadlineTest extends KernelTestCase
{
    private BufferingLogger $log;

    public function testTheBoardPublishedWithoutARoundIsAnEmptyBoardWithItsWaitingOctopusOnTheShellTopic(): void
    {
        $live = new PublishedLiveEvents();

        $this->headlineOn(new DashboardLedger(), $live)->publishTheBoard();

        self::assertCount(1, $live->events);
        self::assertSame(DashboardTopics::SHELL, $live->events[0]->topic);
        self::assertCount(1, new Crawler($live->events[0]->turboStream)->filter('turbo-stream[target="board-octopus"] #board-octopus.firstday'));
    }

    public function testTheBoardPublishedOnceEveryReviewIsJudgedCarriesTheAsleepOctopus(): void
    {
        $ledger = new DashboardLedger();
        $this->judgeRoundsOf($ledger, 1);
        $live = new PublishedLiveEvents();

        $this->headlineOn($ledger, $live)->publishTheBoard();

        self::assertCount(2, new Crawler($live->events[0]->turboStream)->filter('turbo-stream[target="board-octopus"] #board-octopus.asleep:not([hidden]) img[src*="octopus-asleep-"]'));
    }

    public function testTheBoardPublishedWhileABuildIsInFlightCarriesNoOctopus(): void
    {
        $ledger = new DashboardLedger();
        $this->judgeRoundsOf($ledger, 1);
        $ledger->passBuildStep('alpha', 'task-7', 'build_job');
        $live = new PublishedLiveEvents();

        $this->headlineOn($ledger, $live)->publishTheBoard();

        $stream = new Crawler($live->events[0]->turboStream);
        self::assertSame([1, 0], [$stream->filter('turbo-stream[target="board-octopus"] span#board-octopus[hidden]')->count(), $stream->filter('img[src*="octopus-"]')->count()]);
    }

    public function testAPushedDoneColumnCarriesAtMostThePageLimitOfCards(): void
    {
        $done = $this->pushedDoneColumnOf(Board::PAGE_LIMIT + 3);

        self::assertCount(Board::PAGE_LIMIT, $done->filter('article[data-project]'));
    }

    public function testAPushedDoneColumnLinksTheCardsItLeftOutToTheBoard(): void
    {
        $more = $this->pushedDoneColumnOf(Board::PAGE_LIMIT + 3)->filter('.col-cards > a.more-link');

        self::assertSame(['Show 3 more', '/?board=1'], [$more->text(), $more->attr('href')]);
    }

    public function testAPushedDoneColumnStillCountsEveryCard(): void
    {
        $done = $this->pushedDoneColumnOf(Board::PAGE_LIMIT + 3);

        self::assertSame((string) (Board::PAGE_LIMIT + 3), $done->filter('.col-head .count')->text());
    }

    public function testAPushedDoneColumnOfExactlyThePageLimitLeavesNoCardOut(): void
    {
        $done = $this->pushedDoneColumnOf(Board::PAGE_LIMIT);

        self::assertCount(Board::PAGE_LIMIT, $done->filter('article[data-project]'));
        self::assertCount(0, $done->filter('.more-link, .more-btn, [data-past-limit]'));
    }

    public function testAPushedDoneColumnLeavesOutAProjectsCardsPastTheLimitAndCountsThemInItsLink(): void
    {
        $ledger = new DashboardLedger();
        $this->judgeRoundsOf($ledger, Board::PAGE_LIMIT);
        $ledger->startRound('beyond', 'beta');
        $ledger->judgeRound('beyond');

        $done = $this->pushedDoneColumnFrom($ledger);

        self::assertCount(0, $done->filter('article[data-project="beta"]'));
        self::assertSame('Show 1 more', $done->filter('.col-cards > a.more-link')->text());
    }

    public function testAnEmptyDoneColumnPushedAfterTheFirstRoundStillSaysWhatLandsThere(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('change-1', 'r1', 1);

        $done = $this->pushedDoneColumnFrom($ledger);

        self::assertSame(BoardColumn::Done->emptyLine(), $done->filter('.col-empty')->text());
    }

    public function testAnUnchangedBoardIsNotPushedAgain(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('change-1', 'r1', 1);
        $live = new PublishedLiveEvents();
        $headline = $this->headlineOn($ledger, $live);

        $headline->publishLatest(Uuid::v7());
        $headline->publishLatest(Uuid::v7());

        self::assertCount(1, $live->events);
    }

    public function testAnUnchangedBoardIsNotPushedAgainByAnotherWorkerOfTheInstall(): void
    {
        $ledger = new DashboardLedger();
        $live = new PublishedLiveEvents();
        $lastPushed = new ArrayAdapter();

        $this->headlineOn($ledger, $live, $lastPushed)->publishLatest(Uuid::v7());
        $this->headlineOn($ledger, $live, $lastPushed)->publishLatest(Uuid::v7());

        self::assertCount(1, $live->events);
    }

    public function testTheBoardPublishedWithoutARoundIsNotPushedAgainWhenUnchanged(): void
    {
        $live = new PublishedLiveEvents();
        $headline = $this->headlineOn(new DashboardLedger(), $live);

        $headline->publishLatest(Uuid::v7());
        $headline->publishTheBoard();

        self::assertCount(1, $live->events);
    }

    public function testABoardThatChangedSinceTheLastPushIsPushedAgain(): void
    {
        $ledger = new DashboardLedger();
        $live = new PublishedLiveEvents();
        $headline = $this->headlineOn($ledger, $live);

        $headline->publishLatest(Uuid::v7());
        $ledger->startRoundOf('change-1', 'r1', 1);
        $headline->publishLatest(Uuid::v7());

        self::assertCount(2, $live->events);
    }

    public function testAPoolThatCannotBeReadStillPushesTheBoardAndIsLogged(): void
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new InvalidArgumentException('disk full'));
        $live = new PublishedLiveEvents();

        $this->headlineOn(new DashboardLedger(), $live, $pool)->publishTheBoard();

        self::assertCount(1, $live->events);
        self::assertContains(['warning', 'The last pushed board could not be read.'], array_map(static fn (mixed $record): array => is_array($record) ? [$record[0] ?? null, $record[1] ?? null] : [], $this->log->cleanLogs()));
    }

    public function testABoardTheHubRefusedIsPushedAgainOnTheNextTrigger(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 503]), new MockResponse('event-1')]);
        $hub = new Hub('http://localhost/.well-known/mercure', new StaticTokenProvider('publisher.token.unsigned'), httpClient: $http);
        $headline = $this->headlineOn(new DashboardLedger(), new MercureLiveUpdates($hub, $this->log));

        $headline->publishTheBoard();
        $headline->publishTheBoard();

        self::assertSame(2, $http->getRequestsCount());
    }

    public function testAReturnToAnEarlierBoardIsPushedWhenThePoolRefusedTheRecordOfTheNewerOne(): void
    {
        $stored = new ArrayAdapter();
        $refusing = new ArrayObject(['on' => false]);
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback($stored->getItem(...));
        $pool->method('deleteItem')->willReturnCallback($stored->deleteItem(...));
        $pool->method('save')->willReturnCallback(static fn (CacheItemInterface $item): bool => true !== $refusing['on'] && $stored->save($item));
        $live = new PublishedLiveEvents();
        $idle = new DashboardLedger();
        $active = new DashboardLedger();
        $active->startRoundOf('change-1', 'r1', 1);

        $this->headlineOn($idle, $live, $pool)->publishTheBoard();
        $refusing['on'] = true;
        $this->headlineOn($active, $live, $pool)->publishTheBoard();
        $refusing['on'] = false;
        $this->headlineOn($idle, $live, $pool)->publishTheBoard();

        self::assertCount(3, $live->events);
    }

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    private function pushedDoneColumnOf(int $judged): Crawler
    {
        $ledger = new DashboardLedger();
        $this->judgeRoundsOf($ledger, $judged);

        return $this->pushedDoneColumnFrom($ledger);
    }

    private function judgeRoundsOf(DashboardLedger $ledger, int $judged): void
    {
        foreach (range(1, $judged) as $number) {
            $ledger->startRoundOf('change-'.$number, 'r'.$number, 1);
            $ledger->judgeRound('r'.$number);
        }
    }

    private function pushedDoneColumnFrom(DashboardLedger $ledger): Crawler
    {
        $live = new PublishedLiveEvents();
        $this->headlineOn($ledger, $live)->publishLatest(Uuid::v7());

        return new Crawler($live->events[0]->turboStream)->filter('turbo-stream[target="col-done"] section#col-done');
    }

    private function headlineOn(DashboardLedger $ledger, LiveUpdates $live, CacheItemPoolInterface $lastPushed = new ArrayAdapter()): LiveBoardHeadline
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $pages = new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        return new LiveBoardHeadline($pages, $twig, $live, new LockFactory(new InMemoryStore()), $lastPushed, $this->log);
    }
}
