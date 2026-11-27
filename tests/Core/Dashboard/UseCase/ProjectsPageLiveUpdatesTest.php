<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Closure;
use DateTimeImmutable;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierResolver;
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
use Intakt\Tests\Core\Dashboard\Infrastructure\PublishedLiveEvents;
use Intakt\Tests\Journeys\Support\StreamedPage;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

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
#[UsesClass(ProjectNames::class)]
#[UsesClass(ResolvedModel::class)]
final class ProjectsPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private ?string $secretBefore = null;

    // Runs inside the first publish, so while the worker that publishes holds the lock.
    private ?Closure $whilePublishing = null;

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
        // Expected because Messenger's own listener logs every failed message.
        self::assertLoggedError('critical', 'Error thrown while handling message stdClass. Removing from transport after 0 retries. Error: "forge down"');
    }

    public function testATickWhoseWatcherLinesDecideNoPullRequestStatePushesNoCards(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->dispatcher()->dispatch(new WatcherLineRecorded(LedgerEventKind::BootResultComputed));
        $this->dispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'scheduler_default'));

        self::assertSame([], $this->shellUpdates());
    }

    public function testALineNotedBeforeTheServicesWereResetPushesNoCards(): void
    {
        $updates = self::getContainer()->get(ProjectCardsWatcherUpdates::class);
        self::assertInstanceOf(ProjectCardsWatcherUpdates::class, $updates);
        $this->dispatcher()->dispatch(new WatcherLineRecorded(LedgerEventKind::RoundQueued));

        $updates->reset();
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
            $this->withoutTipIds(trim($pushed->filter('turbo-stream[target="project-cards"] template')->html())),
        );
    }

    public function testTheFirstPushedCardKeepsASetupLinkOnAnOpenEmptyPage(): void
    {
        $page = $this->browser->request('GET', '/projects');
        self::assertCount(1, $page->filter('main a[href="/setup"]'));

        $this->pushedCardsAfterAReviewerStarted();

        $shown = new StreamedPage()->afterTopic(DashboardTopics::SHELL, $this->shellUpdates(), $page);
        self::assertCount(1, $shown->filter('[data-project="alpha"]'));
        self::assertCount(1, $shown->filter('main a[href="/setup"]'));
    }

    public function testARoundChangedPushesTheCardsToo(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        self::assertCount(1, $this->shellUpdates());
    }

    public function testAWorkerThatFindsTheLockHeldReturnsWithoutPublishing(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $second = new PublishedLiveEvents();
        $log = new BufferingLogger();
        $other = $this->cardsOfAnotherWorker($second, $log);
        $publishedWhileHeld = null;
        $this->whilePublishing = static function () use ($other, $second, &$publishedWhileHeld): void {
            $other->publishLatest();
            $publishedWhileHeld = count($second->events);
        };

        $this->cards()->publishLatest();
        $other->publishLatest();

        self::assertSame(0, $publishedWhileHeld);
        self::assertSame([], $log->cleanLogs());
        // The same worker publishes once the lock is free, so only the held lock kept it silent before.
        self::assertCount(1, $second->events);
    }

    public function testTheHolderPublishesTheCardsThatChangedWhileItPublishedLast(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $other = $this->cardsOfAnotherWorker(new PublishedLiveEvents(), new NullLogger());
        $this->whilePublishing = function () use ($other): void {
            $this->ledger->startRound('round-2', 'beta');
            $other->publishLatest();
        };

        $this->cards()->publishLatest();

        self::assertSame(
            [['alpha'], ['alpha', 'beta']],
            array_map(static fn (Update $update): array => new Crawler($update->getData())->filter('article[data-project]')->each(static fn (Crawler $card): string => (string) $card->attr('data-project')), $this->shellUpdates()),
        );
    }

    public function testAWorkerThatYieldedWhileTheHolderFailedToReadHasItsChangePublished(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $events = new PublishedLiveEvents();
        // Filled once the workers exist, because the stub must be in the container before the cards read it.
        $meanwhile = new stdClass();
        $meanwhile->other = null;
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'model-a'));
        $tiers->method('projects')->willReturnCallback(function () use ($meanwhile): ProjectNames {
            $other = $meanwhile->other;
            $meanwhile->other = null;

            if ($other instanceof LiveProjectCards) {
                $this->ledger->startRound('round-2', 'beta');
                $other->publishLatest();

                throw new RuntimeException('The cards could not be read.');
            }

            return new ProjectNames();
        });
        self::getContainer()->set(TierResolver::class, $tiers);
        $holder = $this->cardsOfAnotherWorker($events, new NullLogger());
        $meanwhile->other = $this->cardsOfAnotherWorker($events, new NullLogger());

        $holder->publishLatest();

        self::assertSame(
            [['alpha', 'beta']],
            array_map(static fn (LiveEvent $event): array => new Crawler($event->turboStream)->filter('article[data-project]')->each(static fn (Crawler $card): string => (string) $card->attr('data-project')), $events->events),
        );
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
            [$meanwhile, $this->whilePublishing] = [$this->whilePublishing, null];
            $meanwhile?->__invoke();

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

    // Built on the same ledger and lock store, because two workers of one install share both.
    private function cardsOfAnotherWorker(LiveUpdates $updates, LoggerInterface $log): LiveProjectCards
    {
        $cards = self::getContainer()->get(ProjectCards::class);
        self::assertInstanceOf(ProjectCards::class, $cards);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $locks = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $locks);

        return new LiveProjectCards($cards, $twig, $updates, $locks, $log);
    }

    private function cards(): LiveProjectCards
    {
        $cards = self::getContainer()->get(LiveProjectCards::class);
        self::assertInstanceOf(LiveProjectCards::class, $cards);

        return $cards;
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
