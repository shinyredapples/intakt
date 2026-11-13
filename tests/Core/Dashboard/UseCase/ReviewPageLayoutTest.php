<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;

// Only the markup is checked here, because the widths are rules of the sheet, which TaskDetailStylesTest reads.
#[CoversClass(ShowReviewController::class)]
final class ReviewPageLayoutTest extends DashboardPageTestCase
{
    public function testARunningReviewOffersTheTimelineAndLiveLogSwitchWithTheTimelineChosen(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $tabs = $crawler->filter('.ttabs [role="tablist"] button[role="tab"]');
        self::assertSame(['Timeline', 'Live log'], $tabs->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame(['true', 'false'], $tabs->each(static fn (Crawler $tab): ?string => $tab->attr('aria-selected')));
        self::assertSame(['timeline', 'log'], $tabs->each(static fn (Crawler $tab): ?string => $tab->attr('data-pane')));
        self::assertSame('timeline', $crawler->filter('.task-grid')->attr('data-pane'));
    }

    public function testEachTabNamesThePaneItShows(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $controlled = $crawler->filter('.ttabs button[role="tab"]')->each(static fn (Crawler $tab): ?string => $tab->attr('aria-controls'));
        self::assertSame([$crawler->filter('.task-grid > .tl-pane')->attr('id'), $crawler->filter('.task-grid > .log-pane')->attr('id')], $controlled);
        self::assertNotContains(null, $controlled);
    }

    public function testAReviewWithoutALiveLogOffersNoSwitch(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $this->browser->request('GET', '/reviews/round-1');

        self::assertSelectorNotExists('.ttabs');
        self::assertSelectorExists('.task-grid[data-pane="timeline"] > .tl-pane');
    }

    // The page must be roomy, because DESIGN.md's Breakpoints grow only roomy pages past the default width from 1500 px.
    public function testTheReviewPageIsARoomyPage(): void
    {
        $this->givenARunningRound();

        $this->browser->request('GET', '/reviews/round-1');

        self::assertSelectorExists('main.content.roomy');
    }

    public function testStopAndTheStartOfTheNextRoundSitInTheTaskActions(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['round-round-1-now-stop', 'start-review'], $crawler->filter('.page-head .task-actions > *')->each(static fn (Crawler $child): string => $child->attr('id') ?? (string) $child->attr('class')));
    }

    // Left out rather than disabled, because Continue and Send back wait for T59 (D4 of #1794).
    public function testTheTaskActionsOfferNeitherContinueNorSendBack(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Stop'], $crawler->filter('.task-actions button')->each(static fn (Crawler $button): string => $button->text()));
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }
}
