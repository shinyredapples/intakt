<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\ToolCallCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

// The test environment has no hub key (MERCURE_JWT_SECRET is empty), so no listener can be authorized, as when the hub is not set up.
#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(SessionSummary::class)]
#[UsesClass(ToolCallCount::class)]
#[UsesClass(Cost::class)]
#[UsesClass(Finding::class)]
final class ReviewPageWithoutHubTest extends DashboardPageTestCase
{
    public function testThePageRendersTheLedgerStateWhenTheHubCannotAuthorizeAListener(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame('0 of 2 reviewers finished.', $crawler->filter('#round-round-1-state')->text());
        self::assertSame([['correctness', 'standard tier, model not recorded', 'running', 'no answer yet', 'Stop this reviewer']], $crawler->filter('table.reviewers tbody tr')->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => $td->text())));
    }

    public function testThePlainPageStillShowsTheLiveLogBesideTheTimeline(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertResponseIsSuccessful();
        self::assertSame('Live log of review pass 1', $crawler->filter('.task-grid > section.log-pane h2')->text());
        self::assertSame('correctness (standard tier, model not recorded) live', $crawler->filter('.log-pane #reviewer-round-1-correctness h3')->text());
    }

    public function testThePlainPageStillShowsTheSessionSummaryItWasLoadedWith(): void
    {
        $this->givenARunningRound();
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn(new SessionSummary([new ToolCallCount('read_file', 2)], Cost::inUsd(0.3), ['src/A.php'], [], [], [], false));
        self::getContainer()->set(SessionSummaries::class, $summaries);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame('2', $crawler->filter('#round-round-1-summary-tools .ls-tools dd')->first()->text());
        self::assertStringStartsWith('0.3000 USD,', $crawler->filter('#round-round-1-summary-cost')->text());
    }

    public function testThePlainPageStillLoadsTheScriptThatHoldsBackTheFirstClickOnStop(): void
    {
        $this->givenARunningRound();
        $this->browser->disableReboot();
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertCount(1, $crawler->filter('form.stop-reviewer button[data-arm-confirm="Click again to stop"]'));
        self::assertCount(1, $this->browser->request('GET', '/reviews/round-1')->filter('.page-head form.stop-round button[data-arm-confirm="Click again to stop the round"]'));
        self::assertSame(
            [sprintf("import { holdBackDangerButtons } from '%s'; import { offerSearch } from '%s'; import { switchTaskPanes } from '%s'; import { highlightFindings } from '%s'; import { foldColumns } from '%s'; import { showMoreCards } from '%s'; import { toggleBanners } from '%s'; import { markBoardEdges } from '%s'; import { captionTour } from '%s'; import { copyCommands } from '%s'; import { showIdleProjectOctopus } from '%s'; import { filterHistory } from '%s'; holdBackDangerButtons(document); offerSearch(document); switchTaskPanes(document, window); highlightFindings(document); foldColumns(document); showMoreCards(document); toggleBanners(document); markBoardEdges(document, window); captionTour(document, sessionStorage); copyCommands(document, window); showIdleProjectOctopus(document); filterHistory(document, matchMedia('(max-width: 760px)'));", $packages->getUrl('arm.js'), $packages->getUrl('search.js'), $packages->getUrl('task-panes.js'), $packages->getUrl('finding-highlight.js'), $packages->getUrl('column-fold.js'), $packages->getUrl('column-more.js'), $packages->getUrl('banner-toggle.js'), $packages->getUrl('board-edge.js'), $packages->getUrl('tour-caption.js'), $packages->getUrl('copy-command.js'), $packages->getUrl('board-octopus.js'), $packages->getUrl('history-filter.js'))],
            $crawler->filter('head script[type="module"]')->each(static fn (Crawler $script): string => $script->text()),
        );
    }

    public function testTheScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('arm.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function holdBackDangerButtons(page)', $this->browser->getInternalResponse()->getContent());
    }

    public function testTheSearchScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('search.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function offerSearch(page)', $this->browser->getInternalResponse()->getContent());
    }

    public function testThePlainPageShowsTheFindingIdsOfTheSummaryAsHighlightButtonsWithoutTheImportMap(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $finding = new Finding('F1', Severity::P2, 'src/A.php', 1, null, 'Fix it.', FixableBy::Builder, false, null, null);
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn(new SessionSummary([], Cost::none(), [], [$finding], [], [], false));
        self::getContainer()->set(SessionSummaries::class, $summaries);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(0, $crawler->filter('script[type="importmap"]'));
        self::assertSame('F1', $crawler->filter('.log-summary .ls-findings button')->attr('data-highlight-finding'));
        self::assertCount(1, $crawler->filter('#round-round-1 li[data-finding="F1"]'));
    }

    public function testTheHighlightScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('finding-highlight.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function highlightFindings(page)', $this->browser->getInternalResponse()->getContent());
    }

    public function testThePaneSwitchScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('task-panes.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function switchTaskPanes(page, view)', $this->browser->getInternalResponse()->getContent());
    }

    public function testTheColumnFoldScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('column-fold.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function foldColumns(page)', $this->browser->getInternalResponse()->getContent());
    }

    public function testTheShowMoreScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('column-more.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function showMoreCards(page)', $this->browser->getInternalResponse()->getContent());
    }

    public function testTheBoardEdgeScriptThePlainPageImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('board-edge.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function markBoardEdges(page, view)', $this->browser->getInternalResponse()->getContent());
    }

    public function testThePlainPageStillShowsTheToastTheStartButtonLeftAndLogsAWarning(): void
    {
        $this->givenARunningRound();
        $log = new BufferingLogger();
        $request = $this->requestWithTheToast('Started round 2.');

        $page = new Crawler((string) $this->controller($log)($request, 'round-1')->getContent());

        self::assertSame('Started round 2.', $page->filter('.toast')->text());
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertSame(['warning'], array_column($log->cleanLogs(), 0));
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }

    private function requestWithTheToast(string $line): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('toast', $line);
        $request = Request::create('/reviews/round-1');
        $request->setSession($session);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);

        return $request;
    }

    private function controller(BufferingLogger $log): ShowReviewController
    {
        $reviews = self::getContainer()->get(LedgerReviewPages::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(LedgerReviewPages::class, $reviews);
        self::assertInstanceOf(Environment::class, $twig);

        return new ShowReviewController($reviews, new LivePageRendering($twig, $log), self::createStub(SessionSummaries::class));
    }
}
