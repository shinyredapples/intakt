<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

// Only the markup is checked here, because the widths are rules of the sheet, which TaskDetailStylesTest reads.
#[CoversClass(ShowReviewController::class)]
#[UsesClass(Character::class)]
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

    public function testEachPaneTheTabsControlIsATabPanelLabelledByItsTab(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        foreach ($crawler->filter('.ttabs button[role="tab"]')->each(static fn (Crawler $tab): Crawler => $tab) as $tab) {
            $pane = $crawler->filter('#'.$tab->attr('aria-controls'));
            self::assertSame('tabpanel', $pane->attr('role'));
            self::assertSame($tab->attr('id'), $pane->attr('aria-labelledby'));
        }
        self::assertSame(['timeline-tab', 'live-log-tab'], $crawler->filter('.ttabs button[role="tab"]')->each(static fn (Crawler $tab): ?string => $tab->attr('id')));
    }

    public function testTheLiveLogIsATabStopBeforeAnyReviewerHasStarted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $log = $crawler->filter('#live-log-pane');
        self::assertSame('tabpanel', $log->attr('role'));
        self::assertSame('0', $log->attr('tabindex'));
        self::assertCount(0, $crawler->filter('#live-log-pane :is(a[href], button, summary, [tabindex])'));
    }

    // axe's aria-allowed-role refuses role="tabpanel" on an aside, and a section allows it.
    public function testTheLiveLogTabPanelIsASectionSoItsRoleIsAllowed(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('section', $crawler->filter('#live-log-pane')->nodeName());
        self::assertCount(0, $crawler->filter('aside[role="tabpanel"]'));
    }

    public function testEachTabPanelNamesTheHeadingThatLabelsItWhileBothPanesAreShown(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $headings = $crawler->filter('.task-grid > [role="tabpanel"]')->each(static fn (Crawler $pane): ?string => $pane->attr('data-heading'));
        self::assertSame(['timeline-title', 'live-log-title'], $headings);
        foreach ($headings as $heading) {
            self::assertCount(1, $crawler->filter('h2#'.$heading));
        }
    }

    public function testOnlyTheChosenTabStartsInTheTabOrder(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['0', '-1'], $crawler->filter('.ttabs button[role="tab"]')->each(static fn (Crawler $tab): ?string => $tab->attr('tabindex')));
    }

    public function testAReviewWithoutALiveLogOffersNoSwitch(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $this->browser->request('GET', '/reviews/round-1');

        self::assertSelectorNotExists('.ttabs');
        self::assertSelectorExists('.task-grid[data-pane="timeline"] > .tl-pane');
    }

    public function testWithoutTheSwitchTheTimelineIsNoTabPanelAndKeepsItsHeadingAsLabel(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $pane = $crawler->filter('#timeline-pane');
        self::assertNull($pane->attr('role'));
        self::assertSame('timeline-title', $pane->attr('aria-labelledby'));
        self::assertCount(1, $crawler->filter('#timeline-title'));
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

        self::assertSame(['round-round-1-now-runs', 'round-round-1-now-stop', 'start-review'], $crawler->filter('.page-head .task-actions > *')->each(static fn (Crawler $child): string => $child->attr('id') ?? (string) $child->attr('class')));
    }

    // Left out rather than disabled, because Continue and Send back wait for T59 (D4 of #1794).
    public function testTheTaskActionsOfferNeitherContinueNorSendBack(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Stop'], $crawler->filter('.task-actions button')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testEveryReviewerRowInTheNowBlockShowsExactlyOneCodeReviewerHeadOf24Pixels(): void
    {
        $this->givenARunningRound();
        $this->ledger->startReviewerOn('round-1', 'security', 'Kimi K3');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $rows = $crawler->filter('#round-round-1-now-reviewers li');
        self::assertCount(2, $rows);
        foreach ($rows->each(static fn (Crawler $row): Crawler => $row) as $row) {
            $this->assertOneHeadPair($row, 'code_reviewer', 24);
        }
    }

    public function testTheHeadLeavesTheTextOfAReviewerRowInTheNowBlockUnchanged(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['correctness (standard tier, model not recorded): running'], $crawler->filter('#round-round-1-now-reviewers li')->each(static fn (Crawler $row): string => $row->text()));
    }

    public function testTheJudgesCallHeadingShowsOneOwlOf48PixelsOutsideTheElementTheJudgedUpdateReplaces(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $heading = $crawler->filter('.state-block h3')->reduce(static fn (Crawler $h3): bool => "The judge's call" === $h3->text());
        self::assertCount(1, $heading);
        $this->assertOneHeadPair($heading->ancestors()->first(), 'judge', 48);
        self::assertCount(0, $crawler->filter('#round-round-1-now-judge img'));
        self::assertSame('The judge has not judged this round yet.', $crawler->filter('#round-round-1-now-judge')->text());
    }

    public function testNoOtherCharacterAppearsInTheNowBlock(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(4, $crawler->filter('.state-block img.character'));
    }

    public function testEveryLiveLogBlockShowsExactlyOneCodeReviewerHeadOf24PixelsBeforeItsHeading(): void
    {
        $this->givenARunningRound();
        $this->ledger->startReviewerOn('round-1', 'security', 'Kimi K3');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $blocks = $crawler->filter('#round-round-1-live .live');
        self::assertCount(2, $blocks);
        foreach ($blocks->each(static fn (Crawler $block): Crawler => $block) as $block) {
            self::assertCount(2, $block->filter('img'));
            $this->assertOneHeadPair($block->filter('h3')->ancestors()->first(), 'code_reviewer', 24);
            self::assertSame('h3', $block->filter('.character-row > *')->last()->nodeName());
        }
    }

    public function testTheHeadLeavesTheTextOfALiveLogHeadingUnchanged(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['correctness (standard tier, model not recorded) live'], $crawler->filter('#round-round-1-live .live .character-row')->each(static fn (Crawler $row): string => $row->text()));
    }

    public function testTheRoundsFindingsHeadingShowsOneOwlOf24PixelsOutsideTheElementTheJudgedUpdateReplaces(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $heading = $crawler->filter('#round-round-1 h3')->reduce(static fn (Crawler $h3): bool => "Findings and the judge's call" === $h3->text());
        self::assertCount(1, $heading);
        $row = $heading->ancestors()->first();
        $this->assertOneHeadPair($row, 'judge', 24);
        self::assertSame("Findings and the judge's call", $row->text());
        self::assertCount(0, $crawler->filter('#round-round-1-judged img'));
    }

    public function testAJudgedRoundShowsTheSameOwlBesideItsFindingsHeading(): void
    {
        $this->givenARunningRound();
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $heading = $crawler->filter('#round-round-1 h3')->reduce(static fn (Crawler $h3): bool => "Findings and the judge's call" === $h3->text());
        self::assertCount(1, $heading);
        self::assertCount(1, $heading->ancestors()->filter('details.ev'));
        $row = $heading->ancestors()->first();
        $this->assertOneHeadPair($row, 'judge', 24);
        self::assertSame("Findings and the judge's call", $row->text());
    }

    // Both images are checked, because the partial emits one per theme and CSS shows one; the attributes follow D1 and D4 of #2344.
    private function assertOneHeadPair(Crawler $holder, string $character, int $size): void
    {
        $images = $holder->children('img.character');
        self::assertSame(['character character-light', 'character character-dark'], $images->each(static fn (Crawler $img): ?string => $img->attr('class')));
        foreach ($images->each(static fn (Crawler $img): Crawler => $img) as $img) {
            self::assertSame('', $img->attr('alt'));
            self::assertNull($img->attr('role'));
            self::assertSame((string) $size, $img->attr('width'));
            self::assertSame((string) $size, $img->attr('height'));
            self::assertSame('lazy', $img->attr('loading'));
            self::assertMatchesRegularExpression(sprintf('#/%s-(light|dark)-%d[^ ]*\.webp 1x, \S+/%1$s-\1-%d[^ ]*\.webp 2x$#', $character, $size * 2, $size * 4), (string) $img->attr('srcset'));
        }
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }
}
