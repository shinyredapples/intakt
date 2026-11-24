<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DOMNode;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\BoardHeadlineRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ShowBoardController::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(BoardHeadlineRoundUpdates::class)]
#[UsesClass(LiveBoardHeadline::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
final class BoardHeadlineLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private const string FIRST_REVIEW = 'Nothing is running yet. Start the first review.';

    private const string REVIEWING = 'The Code reviewers are reviewing change feat/a of alpha. The Code reviewers are reviewing 1 change.';

    private ?string $secretBefore = null;

    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testTheBoardListensToTheShellTopicWhenLiveUpdatesAreOn(): void
    {
        $source = $this->browser->request('GET', '/')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
    }

    public function testTheBoardsHeadlineIsTheOneElementThePushedHeadlineTargets(): void
    {
        $page = $this->browser->request('GET', '/');

        $target = $this->targetOf($this->pushedHeadlineAfterAReviewerStarted());

        self::assertCount(1, $page->filter('#'.$target));
        self::assertSame(self::FIRST_REVIEW, $page->filter('#'.$target)->text());
        self::assertSame($page->filter('.page-head .lead')->getNode(0), $page->filter('#'.$target)->getNode(0));
    }

    public function testAReviewerStartedAfterThePageLoadedChangesTheBoardsHeadlineThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/');

        $updated = $this->updated($page, $this->pushedHeadlineAfterAReviewerStarted());

        self::assertSame(self::REVIEWING, $updated->filter('.page-head .lead')->text());
    }

    public function testAReviewerStartedAfterThePageLoadedChangesTheStatusLineThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/');
        self::assertSame('Quiet · nobody working', trim($page->filter('#status-line')->text()));

        $pushed = new Crawler($this->pushedHeadlineAfterAReviewerStarted())->filter('turbo-stream[action="replace"][target="status-line"] template');

        self::assertCount(1, $pushed);
        self::assertSame('Running smoothly · 1 reviewer working', trim($pushed->filter('#status-line')->text()));
        $loaded = $this->browser->request('GET', '/');
        self::assertSame(trim($loaded->filter('#status-line')->outerHtml()), trim($pushed->html()));
    }

    public function testThePushedBoardReplacesNothingOnTheStatusPageThatListensToTheSameTopic(): void
    {
        $this->ledger->startRoundOf('feat/b', '0190d1a2-7b3c-7def-8123-456789abcdef', 1);
        $this->ledger->startReviewer('0190d1a2-7b3c-7def-8123-456789abcdef', 'correctness');
        $page = $this->browser->request('GET', '/lanes');
        self::assertCount(1, $page->filter('.working .lane'));

        $targets = new Crawler($this->pushedHeadlineAfterAReviewerStarted())->filter('turbo-stream')
            ->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));

        self::assertNotSame([], $targets);
        self::assertSame([], array_values(array_filter($targets, static fn (string $target): bool => 0 < $page->filter('#'.$target)->count())));
    }

    public function testARoundOpenedBeforeItsFirstReviewerStartsIsInTheHeadlineAndOnWorkingNow(): void
    {
        $page = $this->browser->request('GET', '/');
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $pushed = $this->lastShellUpdate();
        self::assertSame(self::REVIEWING, $this->updated($page, $pushed)->filter('#board-headline')->text());
        self::assertSame(['feat/a'], new Crawler($pushed)->filter('turbo-stream[target="working-now"] template .wcard-title')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testARoundOpenedBeforeItsFirstReviewerStartsIsInTheHeadlineAndOnWorkingNowOfAPageLoadedThen(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(self::REVIEWING, $page->filter('#board-headline')->text());
        self::assertSame(['feat/a'], $page->filter('#working-now a.wcard .wcard-title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame('Reviewing', trim($page->filter('#working-now a.wcard .wcard-head .chip')->text()));
    }

    public function testTheStatusPageHoldsNoElementWithTheIdThePushedBoardGivesWorkingNow(): void
    {
        $target = new Crawler($this->pushedHeadlineAfterAReviewerStarted())->filter('turbo-stream template .w-cards')->closest('turbo-stream')?->attr('target');

        self::assertSame('working-now', $target);
        self::assertCount(0, $this->browser->request('GET', '/lanes')->filter('#'.$target));
    }

    // Morphed with the card the page shows, because then a focused card link keeps the focus, which only the browser check shows the pinned Turbo does.
    public function testAPushWhileAReviewStaysInProgressMorphsTheWorkingNowCardThePageShows(): void
    {
        $this->pushedHeadlineAfterAReviewerStarted();
        $card = $this->browser->request('GET', '/')->filter('#working-now a.wcard');
        self::assertCount(1, $card);

        $this->ledger->startReviewer(self::ROUND, 'correctness');
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'correctness');

        $stream = new Crawler($this->lastShellUpdate())->filter('turbo-stream[action="replace"][method="morph"][target="working-now"]');
        self::assertCount(1, $stream);
        self::assertSame($card->outerHtml(), new Crawler($stream->filter('template')->html())->filter('#working-now a.wcard')->outerHtml());
    }

    public function testAJudgedRoundPushedToTheBoardTakesItsReviewersOutOfTheHeadline(): void
    {
        $page = $this->browser->request('GET', '/');
        $started = $this->pushedHeadlineAfterAReviewerStarted();
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $updated = $this->updated($page, $started.$this->lastShellUpdate());
        self::assertSame('Nothing is running.', $updated->filter('#board-headline')->text());
    }

    public function testAStartedRoundsPushPutsItsCardInTheInReviewColumn(): void
    {
        $this->browser->request('GET', '/');

        $streams = new Crawler($this->pushedHeadlineAfterAReviewerStarted());

        $column = $streams->filter('turbo-stream[action="replace"][target="col-in-review"] template')->first();
        self::assertCount(1, $column);
        self::assertSame('1', trim($column->filter('.col-head .count')->text()));
        self::assertCount(1, $column->filter('.col-cards > [data-project]'));
        self::assertStringContainsString('feat/a', $column->filter('.col-cards')->text());
    }

    public function testAJudgedRoundsPushMovesItsCardFromInReviewToDone(): void
    {
        $this->pushedHeadlineAfterAReviewerStarted();
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $streams = new Crawler($this->lastShellUpdate());
        $inReview = $streams->filter('turbo-stream[target="col-in-review"] template');
        $done = $streams->filter('turbo-stream[target="col-done"] template');
        self::assertCount(0, $inReview->filter('.col-cards > [data-project]'));
        self::assertCount(1, $done->filter('.col-cards > [data-project]'));
    }

    // The page was loaded while the round ran, so its Done is folded and the layout folds the pushed one again: without a strip it could not be opened.
    public function testADoneColumnPushedWhenTheLastRunningRoundIsJudgedKeepsItsStrip(): void
    {
        $this->pushedHeadlineAfterAReviewerStarted();
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $done = new Crawler($this->lastShellUpdate())->filter('turbo-stream[target="col-done"] template');
        self::assertCount(1, $done->filter('button.col-fold[aria-controls="cards-done"]'));
        self::assertCount(1, $done->filter('.col-head button.col-hide'));
    }

    public function testAStartedRoundsPushReplacesWorkingNowWithItsCard(): void
    {
        $this->browser->request('GET', '/');

        $working = new Crawler($this->pushedHeadlineAfterAReviewerStarted())->filter('turbo-stream[action="replace"][target="working-now"]');

        self::assertCount(1, $working);
        self::assertSame(['feat/a'], $working->filter('template .wcard-title')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testAJudgedRoundsPushTakesItsCardOutOfWorkingNow(): void
    {
        $this->pushedHeadlineAfterAReviewerStarted();
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $working = new Crawler($this->lastShellUpdate())->filter('turbo-stream[action="replace"][target="working-now"] template');
        self::assertCount(0, $working->filter('.wcard'));
        self::assertCount(1, $working->filter('p#working-now.working-none'));
    }

    public function testThePushedWorkingNowIsTheSameMarkupAsTheSectionOfAPageLoadedAfterIt(): void
    {
        $streams = new Crawler($this->pushedHeadlineAfterAReviewerStarted());

        $page = $this->browser->request('GET', '/');

        $pushed = $streams->filter('turbo-stream[target="working-now"] template')->first();
        self::assertSame($page->filter('#working-now')->outerHtml(), trim($pushed->html()));
    }

    public function testThePushedColumnIsTheSameMarkupAsTheColumnOfAPageLoadedAfterIt(): void
    {
        $streams = new Crawler($this->pushedHeadlineAfterAReviewerStarted());

        $page = $this->browser->request('GET', '/');

        $pushed = $streams->filter('turbo-stream[target="col-in-review"] template')->first();
        self::assertSame($this->withoutTipIds($page->filter('#col-in-review')->outerHtml()), $this->withoutTipIds(trim($pushed->html())));
    }

    public function testTheFirstReviewPushedToAFreshBoardTakesTheWaitingOctopusAway(): void
    {
        $page = $this->browser->request('GET', '/');
        self::assertCount(2, $page->filter('#board-octopus img[src*="octopus-waiting-"]'));

        $updated = $this->replaced($page, $this->pushedHeadlineAfterAReviewerStarted());

        self::assertCount(1, $updated->filter('#col-in-review .col-cards > [data-project]'));
        self::assertCount(0, $updated->filter('img[src*="octopus-"]'));
    }

    public function testABoardPushedBeforeAnyReviewKeepsTheWaitingOctopusAsAPageLoadedAfterIt(): void
    {
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $pushed = new Crawler($this->lastShellUpdate())->filter('turbo-stream[action="replace"][target="board-octopus"] template');

        $page = $this->browser->request('GET', '/');
        self::assertCount(1, $pushed);
        self::assertSame($page->filter('#board-octopus')->outerHtml(), trim($pushed->html()));
    }

    public function testTheFirstReviewPushedToAFreshBoardKeepsAnEmptyOctopusTarget(): void
    {
        $page = $this->browser->request('GET', '/');

        $updated = $this->replaced($page, $this->pushedHeadlineAfterAReviewerStarted());

        self::assertCount(1, $updated->filter('main > #board-octopus[hidden]:empty'));
        self::assertCount(0, $updated->filter('.firstday'));
    }

    public function testABoardPushedEmptyAfterAReviewBringsTheWaitingOctopusBackWithoutAReload(): void
    {
        $page = $this->browser->request('GET', '/');
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');
        $empty = $this->lastShellUpdate();
        $afterAReview = $this->pushedHeadlineAfterAReviewerStarted();

        $updated = $this->replaced($this->replaced($page, $afterAReview), $empty);

        self::assertCount(2, $updated->filter('main > #board-octopus.firstday img[src*="octopus-waiting-"]'));
    }

    public function testABoardPushedEmptyToAPageLoadedAfterAReviewBringsBackTheSetUpActionItsPanelAsksFor(): void
    {
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');
        $empty = $this->lastShellUpdate();
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $page = $this->browser->request('GET', '/');
        self::assertCount(0, $page->filter('.page-head .actions a[href="/setup"]'));

        $updated = $this->replaced($page, $empty);

        self::assertStringContainsString('Choose Set up Intakt', $updated->filter('.firstday ol > li')->first()->text());
        self::assertCount(1, $updated->filter('.page-head .actions a[href="/setup"]'));
        self::assertCount(1, $updated->filter('.page-head .actions a[href="/reviews"]'));
    }

    public function testTheFirstReviewPushedToAFreshBoardTakesTheSetUpActionOutOfTheHead(): void
    {
        $page = $this->browser->request('GET', '/');
        self::assertCount(1, $page->filter('.page-head .actions a[href="/setup"]'));

        $updated = $this->replaced($page, $this->pushedHeadlineAfterAReviewerStarted());

        self::assertCount(0, $updated->filter('.page-head .actions a[href="/setup"]'));
        self::assertCount(1, $updated->filter('.page-head .actions a[href="/reviews"]'));
    }

    public function testThePushedSetUpSlotIsTheSameMarkupAsTheSlotOfAPageLoadedAfterIt(): void
    {
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $pushed = new Crawler($this->lastShellUpdate())->filter('turbo-stream[action="replace"][target="board-setup"] template');

        $page = $this->browser->request('GET', '/');
        self::assertCount(1, $pushed);
        self::assertSame($page->filter('#board-setup')->outerHtml(), trim($pushed->html()));
    }

    public function testABoardPushedEmptyToAnEmptyBoardMorphsTheSetUpSlotInsteadOfReplacingItsLink(): void
    {
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $streams = new Crawler($this->lastShellUpdate());

        self::assertCount(1, $streams->filter('turbo-stream[action="replace"][method="morph"][target="board-setup"] template a[href="/setup"]'));
        self::assertCount(0, $streams->filter('turbo-stream[action="replace"][target="board-setup"]:not([method="morph"])'));
    }

    public function testABoardPushedEmptyToAnEmptyBoardMorphsTheHeadlineSoItsFirstDayLinkKeepsTheKeyboardFocus(): void
    {
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $streams = new Crawler($this->lastShellUpdate());

        self::assertCount(1, $streams->filter('turbo-stream[action="update"][method="morph"][target="board-headline"] template a[href="/setup"]'));
        self::assertCount(0, $streams->filter('turbo-stream[action="update"][target="board-headline"]:not([method="morph"])'));
    }

    // board-push-focus.browser.mjs applies these files with the pinned Turbo, so they must stay what the board renders and pushes.
    public function testTheBrowserChecksPageHeadIsTheHeadOfAnEmptyBoard(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame($this->browserFixture('head.html'), $page->filter('.page-head')->outerHtml());
    }

    public function testTheBrowserChecksStreamsAreTheMorphsPushedToAnEmptyBoard(): void
    {
        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $morphs = new Crawler($this->lastShellUpdate())->filter('turbo-stream[method="morph"]')->each(static fn (Crawler $stream): string => $stream->outerHtml());

        self::assertSame($this->browserFixture('streams.html'), implode("\n", $morphs));
    }

    public function testThePushedBoardLeavesTheReviewListLinkInPlaceSoItKeepsTheKeyboardFocus(): void
    {
        $page = $this->browser->request('GET', '/');
        $link = $page->filter('.page-head .actions a[href="/reviews"]')->getNode(0);
        self::assertNotNull($link);

        $updated = $this->replaced($page, $this->pushedHeadlineAfterAReviewerStarted());

        self::assertSame($link, $updated->filter('.page-head .actions a[href="/reviews"]')->getNode(0));
        self::assertStringNotContainsString('href="/reviews"', $this->lastShellUpdate());
    }

    public function testABoardPageLoadedAfterAReviewHoldsAnEmptyHiddenOctopusTarget(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $page = $this->browser->request('GET', '/');

        self::assertCount(1, $page->filter('main > #board-octopus[hidden]:empty'));
        self::assertCount(0, $page->filter('.firstday'));
    }

    public function testTheBoardShowsTheCurrentHeadlineWithoutAStreamSourceWhenLiveUpdatesAreOff(): void
    {
        $this->givenLiveUpdatesAreOff();
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $page = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertSame(self::REVIEWING, $page->filter('#board-headline')->text());
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

    // Without a hub key no listener can be authorized, as when the hub is not set up; read when the kernel boots, so it takes a new one.
    private function givenLiveUpdatesAreOff(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = '';
        parent::setUp();
    }

    private function givenTheHubRecordsWhatIsPublished(): void
    {
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, new NullLogger()));
    }

    private function pushedHeadlineAfterAReviewerStarted(): string
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        return $this->lastShellUpdate();
    }

    // A term's tooltip id is random so that ids stay unique on a page, so two renders of one column differ only there.
    private function withoutTipIds(string $html): string
    {
        return (string) preg_replace('/term-([a-z]+)-\d+/', 'term-$1', $html);
    }

    private function roundUpdates(): RoundUpdates
    {
        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);

        return $updates;
    }

    private function lastShellUpdate(): string
    {
        $shell = array_filter($this->published, static fn (Update $update): bool => [DashboardTopics::SHELL] === $update->getTopics() && !str_contains($update->getData(), 'target="project-cards"'));
        $last = array_last($shell);
        self::assertNotNull($last, 'Nothing was published on the shell topic.');

        return $last->getData();
    }

    private function targetOf(string $stream): string
    {
        $update = new Crawler($stream)->filter('turbo-stream[action="update"]');
        self::assertCount(1, $update);

        return (string) $update->attr('target');
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests; like Turbo's update, it replaces the target's children and keeps the target.
    private function updated(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream[action="update"]')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $target = $page->filter('#'.$stream->attr('target'))->getNode(0);
            $template = $stream->filter('template')->getNode(0);
            self::assertNotNull($target);
            self::assertNotNull($template);
            $document = $target->ownerDocument;
            self::assertNotNull($document);
            while ($target->firstChild instanceof DOMNode) {
                $target->removeChild($target->firstChild);
            }

            foreach ($template->childNodes as $child) {
                $target->appendChild($document->importNode($child, true));
            }
        }

        return $page;
    }

    private function browserFixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/board-push-focus/'.$name);
        self::assertIsString($contents);

        return rtrim($contents, "\n");
    }

    // Like Turbo's replace, a morph too, because only board-push-focus.browser.mjs shows whether the pinned Turbo keeps a morphed node and its focus.
    private function replaced(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream[action="replace"]')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $template = $stream->filter('template')->getNode(0);
            self::assertNotNull($template);

            foreach ($page->filter('[id="'.$stream->attr('target').'"]')->each(static fn (Crawler $old): ?DOMNode => $old->getNode(0)) as $target) {
                self::assertNotNull($target);
                $parent = $target->parentNode;
                $document = $target->ownerDocument;
                self::assertNotNull($parent);
                self::assertNotNull($document);

                foreach ($template->childNodes as $child) {
                    $parent->insertBefore($document->importNode($child, true), $target);
                }

                $parent->removeChild($target);
            }
        }

        return $page;
    }
}
