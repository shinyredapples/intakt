<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use DOMNode;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesChanged;
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
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\LiveWaitingPage;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageRoundUpdates;
use Intakt\Core\Dashboard\UseCase\ListWaiting\ListWaitingController;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Tests\Core\Dashboard\Infrastructure\ChangingOwnerNotices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ListWaitingController::class)]
#[CoversClass(LiveWaitingPage::class)]
#[CoversClass(WaitingPageRoundUpdates::class)]
#[UsesClass(WaitingPageReading::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
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
final class WaitingPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private ?string $secretBefore = null;

    private ChangingOwnerNotices $notices;

    private bool $hubRefuses = false;

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
        // One kernel for the whole test, because the page, the pushed update and the page loaded again read the same notices and ledger.
        $this->browser->disableReboot();
        $this->givenTheHubRecordsWhatIsPublished();
        $this->givenTheDownloadedScriptsExist();
        $this->notices = new ChangingOwnerNotices();
        $this->notices->running = [new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-10-28T08:00:00+00:00'), new DateTimeImmutable('2026-10-28T11:00:00+00:00'))];
        self::getContainer()->set(OwnerNotices::class, $this->notices);
    }

    #[Override]
    protected function tearDown(): void
    {
        $secret = $this->secretBefore ?? '';
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = $secret;
        $this->removeTheStandIns();
        parent::tearDown();
    }

    public function testTheWaitingPageListensToTheWaitingTopicWhenLiveUpdatesAreOn(): void
    {
        $source = $this->browser->request('GET', '/waiting')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::WAITING), (string) $source->attr('src'));
    }

    public function testAnOutageThatEndedAfterThePageLoadedLeavesTheListAndShowsUnderYouHandledTodayThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/waiting');
        self::assertCount(1, $page->filter('.wait-item'));

        $this->theOutageEndsAt(new DateTimeImmutable('2026-10-28T11:30:00+00:00'));

        $updated = $this->applied($page, $this->lastWaitingUpdate());
        self::assertCount(0, $updated->filter('.wait-item'));
        self::assertSame('Nothing needs you', $updated->filter('#waiting-list .empty h2')->text());
        self::assertSame('Things only you can do show up here, oldest first.', $updated->filter('#waiting-lead')->text());
        self::assertSame('Readings of account claude_code.main came back11:30 UTC', $updated->filter('#waiting-side li')->text());
    }

    public function testAReviewerStartedAfterThePageLoadedPutsItsReviewUnderMeanwhileThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/waiting');
        self::assertSame('No review is running now.', $page->filter('#waiting-side .none')->first()->text());

        $this->aReviewerStarts();

        $updated = $this->applied($page, $this->lastWaitingUpdate());
        self::assertSame('feat/a of alpha is in review, round 1', $updated->filter('#waiting-side li a')->text());
        self::assertSame('/reviews/'.self::ROUND, $updated->filter('#waiting-side li a')->attr('href'));
    }

    public function testAJudgedRoundPushedToThePageTakesItsReviewOutOfMeanwhile(): void
    {
        $this->aReviewerStarts();
        $page = $this->browser->request('GET', '/waiting');
        self::assertCount(1, $page->filter('#waiting-side li a'));
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $updated = $this->applied($page, $this->lastWaitingUpdate());
        self::assertCount(0, $updated->filter('#waiting-side li a'));
        self::assertSame('No review is running now.', $updated->filter('#waiting-side .none')->first()->text());
    }

    public function testThePushedLeadListAndPanelsAreTheSameMarkupAsThoseOfAPageLoadedAfterIt(): void
    {
        $this->aReviewerStarts();
        $streams = new Crawler($this->lastWaitingUpdate());

        $page = $this->browser->request('GET', '/waiting');

        self::assertSame($page->filter('#waiting-lead')->html(), $streams->filter('turbo-stream[target="waiting-lead"] template')->html());
        foreach (['waiting-list', 'waiting-side'] as $target) {
            self::assertSame($page->filter('#'.$target)->outerHtml(), trim($streams->filter('turbo-stream[target="'.$target.'"] template')->html()), $target);
        }
    }

    // A hub that is down refuses the publication, and the reading that ended the outage must still count.
    public function testAHubThatRefusesThePublicationFailsNeitherTheChangeNorThePage(): void
    {
        $this->hubRefuses = true;

        $this->theOutageEndsAt(new DateTimeImmutable('2026-10-28T11:30:00+00:00'));
        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->published);
        self::assertSame('Readings of account claude_code.main came back11:30 UTC', $page->filter('#waiting-side li')->text());
    }

    // Without a hub key no listener can be authorized, as when the hub is not set up; the page then shows everything a live one would.
    public function testTheWaitingPageRendersItsListAndPanelsWithoutAStreamSourceWhenLiveUpdatesAreOff(): void
    {
        $this->givenLiveUpdatesAreOff();
        $this->aReviewerStartsInTheLedger();
        $this->notices->ended = [new EndedOutageNotice(new Account(Provider::ClaudeCode, 'other'), new DateTimeImmutable('2026-10-28T09:14:00+00:00'))];

        $page = $this->browser->request('GET', '/waiting');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertCount(1, $page->filter('#waiting-list .wait-item'));
        self::assertSame('feat/a of alpha is in review, round 1', $page->filter('#waiting-side li a')->text());
        self::assertSame('Readings of account claude_code.other came back09:14 UTC', $page->filter('#waiting-side li')->last()->text());
    }

    // Read when the kernel boots, so it takes a new one, with the same notices.
    private function givenLiveUpdatesAreOff(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = '';
        parent::setUp();
        self::getContainer()->set(OwnerNotices::class, $this->notices);
    }

    private function givenTheHubRecordsWhatIsPublished(): void
    {
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            if ($this->hubRefuses) {
                throw new RuntimeException('The hub is down.');
            }
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, new NullLogger()));
    }

    private function theOutageEndsAt(DateTimeImmutable $moment): void
    {
        $this->notices->running = [];
        $this->notices->ended = [new EndedOutageNotice(new Account(Provider::ClaudeCode, 'main'), $moment)];
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch(new OwnerNoticesChanged());
    }

    private function aReviewerStarts(): void
    {
        $this->aReviewerStartsInTheLedger();
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');
    }

    private function aReviewerStartsInTheLedger(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');
    }

    private function roundUpdates(): RoundUpdates
    {
        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);

        return $updates;
    }

    private function lastWaitingUpdate(): string
    {
        $waiting = array_filter($this->published, static fn (Update $update): bool => [DashboardTopics::WAITING] === $update->getTopics());
        $last = array_last($waiting);
        self::assertNotNull($last, 'Nothing was published on the waiting topic.');

        return $last->getData();
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests: an update replaces the target's children, a replace the target itself.
    private function applied(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $target = $page->filter('#'.$stream->attr('target'))->getNode(0);
            $template = $stream->filter('template')->getNode(0);
            self::assertNotNull($target);
            self::assertNotNull($template);

            'replace' === $stream->attr('action') ? $this->replaced($target, $template) : $this->updated($target, $template);
        }

        return $page;
    }

    private function replaced(DOMNode $target, DOMNode $template): void
    {
        $document = $target->ownerDocument;
        $parent = $target->parentNode;
        self::assertNotNull($document);
        self::assertNotNull($parent);

        foreach (iterator_to_array($template->childNodes) as $child) {
            $parent->insertBefore($document->importNode($child, true), $target);
        }

        $parent->removeChild($target);
    }

    private function updated(DOMNode $target, DOMNode $template): void
    {
        $document = $target->ownerDocument;
        self::assertNotNull($document);

        while ($target->firstChild instanceof DOMNode) {
            $target->removeChild($target->firstChild);
        }

        foreach (iterator_to_array($template->childNodes) as $child) {
            $target->appendChild($document->importNode($child, true));
        }
    }
}
