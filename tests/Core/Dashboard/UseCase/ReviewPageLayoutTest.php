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
