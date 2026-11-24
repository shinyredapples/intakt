<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\ProjectCard;
use Intakt\Core\Dashboard\Infrastructure\LiveProjectCards;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\ProjectCards;
use Intakt\Core\Dashboard\Infrastructure\ProjectCardsRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\ProjectCardsWatcherUpdates;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Watcher\Contract\WatcherLineRecorded;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Journeys\Support\StreamedPage;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LiveProjectCards::class)]
#[CoversClass(ProjectCardsRoundUpdates::class)]
#[CoversClass(ProjectCardsWatcherUpdates::class)]
#[CoversClass(WatcherLedger::class)]
#[UsesClass(ListProjectsController::class)]
#[UsesClass(WatcherLineRecorded::class)]
#[UsesClass(StreamedPage::class)]
#[UsesClass(ProjectCards::class)]
#[UsesClass(ProjectCard::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
final class ProjectsPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private ?string $secretBefore = null;

    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testThePageListensToTheShellTopicTheCardsArePushedOn(): void
    {
        $source = $this->browser->request('GET', '/projects')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
    }

    public function testAReviewerStartedAfterThePageLoadedChangesItsProjectsCardThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/projects');
        self::assertCount(0, $page->filter('[data-project="alpha"]'));

        $this->pushedCardsAfterAReviewerStarted();

        $shown = new StreamedPage()->afterTopic(DashboardTopics::SHELL, $this->shellUpdates(), $page);
        self::assertSame('Running', $shown->filter('[data-project="alpha"] .state')->text());
        self::assertCount(1, $shown->filter('[data-project="alpha"] .dot.ok'));
    }

    public function testAHeadTheWatcherFailedAfterThePageLoadedTurnsItsCardFailingThroughThePushedUpdate(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $page = $this->browser->request('GET', '/projects');
        self::assertCount(0, $page->filter('[data-project="alpha"] .failing'));
        $at = new DateTimeImmutable('2026-10-28T12:00:00+00:00');

        $this->watcherLedger()->headFailed(new HeadFailure('alpha', new OpenChange(new ChangeId('7'), new CommitId(str_repeat('a', 40)), $at), 'the checks timed out'), 2, $at);
        $this->dispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'scheduler_default'));

        $shown = new StreamedPage()->afterTopic(DashboardTopics::SHELL, $this->shellUpdates(), $page);
        self::assertSame('Failing: the checks timed out', $shown->filter('[data-project="alpha"] .failing')->text());
    }

    public function testAWatcherLineIsPushedOnlyOnceTheWorkerIsDoneWithItsTick(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->dispatcher()->dispatch(new WatcherLineRecorded(LedgerEventKind::HeadFailed));

        self::assertSame([], $this->shellUpdates());
    }

    public function testATickThatFailedAfterAWatcherLinePushesTheCards(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->dispatcher()->dispatch(new WatcherLineRecorded(LedgerEventKind::HeadHandedOver));
        $this->dispatcher()->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'scheduler_default', new RuntimeException('forge down')));

        self::assertCount(1, $this->shellUpdates());
        // Messenger's own listener logs the failed tick, which this test only stands in for.
        self::assertLoggedError('critical', 'Error thrown while handling message stdClass. Removing from transport after 0 retries. Error: "forge down"');
    }

    public function testATickWhoseWatcherLinesDecideNoPullRequestStatePushesNoCards(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->dispatcher()->dispatch(new WatcherLineRecorded(LedgerEventKind::BootResultComputed));
        $this->dispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'scheduler_default'));

        self::assertSame([], $this->shellUpdates());
    }

    public function testTheNextTickWithoutAWatcherLinePushesNoCardsAgain(): void
    {
        $this->dispatcher()->dispatch(new WatcherLineRecorded(LedgerEventKind::RoundQueued));
        $this->dispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'scheduler_default'));

        $this->dispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'scheduler_default'));

        self::assertCount(1, $this->shellUpdates());
    }

    public function testThePushedCardsAreTheOnesAPageLoadedAfterwardsShows(): void
    {
        $pushed = $this->pushedCardsAfterAReviewerStarted();

        $loaded = $this->browser->request('GET', '/projects');

        self::assertSame(
            $this->withoutTipIds($loaded->filter('#project-cards')->outerHtml()),
            $this->withoutTipIds(trim($pushed->filter('template')->html())),
        );
    }

    public function testARoundChangedPushesTheCardsToo(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertCount(1, $this->shellUpdates());
    }

    public function testAToolCallPushesNoCards(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->roundUpdates()->toolCalled(Uuid::fromString(self::ROUND), 'security', 'read_file');

        self::assertSame([], $this->shellUpdates());
    }

    #[Override]
    protected function setUp(): void
    {
        $before = $_SERVER['MERCURE_JWT_SECRET'] ?? null;
        $this->secretBefore = is_string($before) ? $before : null;
        // A fresh random key, because a committed one would be a secret; the page only signs a listener's token with it.
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(16));
        parent::setUp();
        // One kernel for the whole test, because the page, the pushed update and the page loaded again read the same ledger.
        $this->browser->disableReboot();
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, new NullLogger()));
        $this->givenTheDownloadedScriptsExist();
    }

    #[Override]
    protected function tearDown(): void
    {
        $secret = $this->secretBefore ?? '';
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = $secret;
        $this->removeTheStandIns();
        parent::tearDown();
    }

    private function pushedCardsAfterAReviewerStarted(): Crawler
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $last = array_last($this->shellUpdates());
        self::assertNotNull($last, 'Nothing was published on the shell topic.');

        return new Crawler($last->getData());
    }

    // A term's tooltip id is random so that ids stay unique on a page, so two renders of one list differ there; a figure's id is stripped too, as it names its place on the page.
    private function withoutTipIds(string $html): string
    {
        return (string) preg_replace('/(term|figure)-([a-z]+-)?\d+/', '$1', $html);
    }

    private function watcherLedger(): WatcherLedger
    {
        $ledger = self::getContainer()->get(WatcherLedger::class);
        self::assertInstanceOf(WatcherLedger::class, $ledger);

        return $ledger;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function roundUpdates(): RoundUpdates
    {
        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);

        return $updates;
    }

    /**
     * @return list<Update>
     */
    private function shellUpdates(): array
    {
        return array_values(array_filter(
            $this->published,
            static fn (Update $update): bool => [DashboardTopics::SHELL] === $update->getTopics() && str_contains($update->getData(), 'target="project-cards"'),
        ));
    }
}
