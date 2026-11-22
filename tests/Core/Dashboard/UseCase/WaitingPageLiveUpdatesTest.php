<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesChanged;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\Elapsed;
use Intakt\Core\Dashboard\Domain\HandledLine;
use Intakt\Core\Dashboard\Domain\MeanwhileLine;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\LiveWaitingPage;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageRoundUpdates;
use Intakt\Core\Dashboard\UseCase\ListWaiting\ListWaitingController;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ListWaitingController::class)]
#[CoversClass(LiveWaitingPage::class)]
#[CoversClass(WaitingPageRoundUpdates::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(WaitingPage::class)]
#[UsesClass(WaitingItem::class)]
#[UsesClass(MeanwhileLine::class)]
#[UsesClass(HandledLine::class)]
#[UsesClass(Character::class)]
#[UsesClass(Elapsed::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(EndedOutageNotice::class)]
#[UsesClass(OwnerNoticesOfTheDay::class)]
#[UsesClass(OwnerNoticesChanged::class)]
#[UsesClass(Account::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(RoundProgress::class)]
final class WaitingPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private ?string $secretBefore = null;

    private OwnerNoticesOfTheDay $notices;

    private bool $hubIsDown = false;

    private BufferingLogger $hubLog;

    /**
     * @var list<Update>
     */
    private array $published = [];

    #[Override]
    protected function setUp(): void
    {
        $before = $_SERVER['MERCURE_JWT_SECRET'] ?? null;
        $this->secretBefore = is_string($before) ? $before : null;
        // A fresh random key, because a committed one would be a secret; the page only signs a listener's token with it.
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(16));
        parent::setUp();
        // One kernel for the whole test, because the page, the pushed update and the page loaded again read the same notices.
        $this->browser->disableReboot();
        $this->notices = new OwnerNoticesOfTheDay([], []);
        $this->hubLog = new BufferingLogger();
        $this->givenTheNoticesAreReadFromTheTest();
        $this->givenTheHubRecordsWhatIsPublished();
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

    public function testTheWaitingPageListensToItsOwnTopicBesideTheShellTopic(): void
    {
        $source = $this->browser->request('GET', '/waiting')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::WAITING), (string) $source->attr('src'));
    }

    public function testANoticeOpenedAfterThePageLoadedChangesThePageThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/waiting');
        self::assertCount(0, $page->filter('.wait-item'));

        $this->notices = new OwnerNoticesOfTheDay([$this->noticeOf('main')], []);
        $this->noticesChanged();

        $updated = $this->replaced($page, $this->lastWaitingUpdate());
        self::assertSame('1 thing needs you', $updated->filter('#waiting-head h1')->text());
        self::assertSame(['No usage of account claude_code.main has been read since 2026-10-28 08:00 UTC'], $updated->filter('#waiting-list .wait-item h2')->each(static fn (Crawler $lead): string => $lead->text()));
    }

    public function testANoticeEndedAfterThePageLoadedMovesItToYouHandledTodayThroughThePushedUpdate(): void
    {
        $this->notices = new OwnerNoticesOfTheDay([$this->noticeOf('main')], []);
        $page = $this->browser->request('GET', '/waiting');
        self::assertCount(1, $page->filter('.wait-item'));

        $this->notices = new OwnerNoticesOfTheDay([], [new EndedOutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-10-28T11:30:00+00:00'))]);
        $this->noticesChanged();

        $updated = $this->replaced($page, $this->lastWaitingUpdate());
        self::assertCount(0, $updated->filter('.wait-item'));
        self::assertSame('Waiting on you', $updated->filter('#waiting-head h1')->text());
        self::assertSame(['Readings of account claude_code.main came back'], $updated->filter('#waiting-side section[aria-labelledby="handled-h"] li span:first-child')->each(static fn (Crawler $text): string => $text->text()));
    }

    public function testARoundOpenedAfterThePageLoadedAddsItsMeanwhileLineThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/waiting');
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);
        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $updated = $this->replaced($page, $this->lastWaitingUpdate());
        self::assertSame(['feat/a of alpha is in review, round 1'], $updated->filter('#waiting-side li a')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testThePushedPageIsTheSameMarkupAsThePageLoadedAfterIt(): void
    {
        $this->notices = new OwnerNoticesOfTheDay([$this->noticeOf('main')], []);
        $this->noticesChanged();
        $streams = new Crawler($this->lastWaitingUpdate());

        $page = $this->browser->request('GET', '/waiting');

        foreach (['waiting-head', 'waiting-list', 'waiting-side'] as $target) {
            self::assertSame($page->filter('#'.$target)->outerHtml(), trim($streams->filter('turbo-stream[target="'.$target.'"] template')->html()), $target);
        }
    }

    public function testAPushedPageTargetsOnlyElementsTheBoardDoesNotHold(): void
    {
        $this->noticesChanged();
        $board = $this->browser->request('GET', '/');

        $targets = new Crawler($this->lastWaitingUpdate())->filter('turbo-stream')->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));

        self::assertSame([], array_values(array_filter($targets, static fn (string $target): bool => 0 < $board->filter('#'.$target)->count())));
    }

    public function testTheWaitingPageShowsTheCurrentNoticesWithoutAStreamSourceWhenLiveUpdatesAreOff(): void
    {
        $this->givenLiveUpdatesAreOff();
        $this->notices = new OwnerNoticesOfTheDay([$this->noticeOf('main')], []);

        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertCount(1, $page->filter('#waiting-list .wait-item'));
    }

    public function testANoticeThatChangesWhileTheHubIsDownIsLoggedAndThePageStillLoads(): void
    {
        $this->hubIsDown = true;
        $this->notices = new OwnerNoticesOfTheDay([$this->noticeOf('main')], []);

        $this->noticesChanged();
        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        self::assertSame(['A live dashboard update was not published.'], array_map(static fn (array $record): mixed => $record[1], $this->hubLog->cleanLogs()));
        self::assertSame([], $this->published);
        self::assertCount(1, $page->filter('#waiting-list .wait-item'));
    }

    // Without a hub key no listener can be authorized, as when the hub is not set up; read when the kernel boots, so it takes a new one.
    private function givenLiveUpdatesAreOff(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = '';
        parent::setUp();
        $this->givenTheNoticesAreReadFromTheTest();
    }

    private function givenTheNoticesAreReadFromTheTest(): void
    {
        $stub = self::createStub(OwnerNotices::class);
        $stub->method('waitingAndEndedOnTheDayOf')->willReturnCallback(fn (): OwnerNoticesOfTheDay => $this->notices);
        self::getContainer()->set(OwnerNotices::class, $stub);
    }

    private function givenTheHubRecordsWhatIsPublished(): void
    {
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            if ($this->hubIsDown) {
                throw new RuntimeException('Connection refused');
            }

            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, $this->hubLog));
    }

    private function noticesChanged(): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch(new OwnerNoticesChanged());
    }

    private function lastWaitingUpdate(): string
    {
        $waiting = array_filter($this->published, static fn (Update $update): bool => [DashboardTopics::WAITING] === $update->getTopics());
        $last = array_last($waiting);
        self::assertNotNull($last, 'Nothing was published on the Waiting on you topic.');

        return $last->getData();
    }

    private function noticeOf(string $account): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, $account), new DateTimeImmutable('2026-10-28T08:00:00+00:00'), new DateTimeImmutable('2026-10-28T11:00:00+00:00'));
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests; like Turbo's replace, a morph too, it puts the template's children where the target was.
    private function replaced(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream[action="replace"]')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $template = $stream->filter('template')->getNode(0);
            $target = $page->filter('[id="'.$stream->attr('target').'"]')->getNode(0);
            self::assertNotNull($template);
            self::assertNotNull($target, 'The page holds no element #'.$stream->attr('target').'.');
            $parent = $target->parentNode;
            $document = $target->ownerDocument;
            self::assertNotNull($parent);
            self::assertNotNull($document);

            foreach ($template->childNodes as $child) {
                $parent->insertBefore($document->importNode($child, true), $target);
            }

            $parent->removeChild($target);
        }

        return $page;
    }
}
