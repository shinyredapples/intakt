<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartScale;
use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\ReviewerCatch;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowTrends\ShowTrendsController;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\SampleChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowTrendsController::class)]
#[CoversClass(LedgerReviewPages::class)]
#[UsesClass(ReviewTrends::class)]
#[UsesClass(ReviewerCatches::class)]
#[UsesClass(ReviewerCatch::class)]
#[UsesClass(JudgeOutcomes::class)]
#[UsesClass(ChartBar::class)]
#[UsesClass(ChartScale::class)]
#[UsesClass(SampleChangeSource::class)]
#[UsesClass(ConfiguredPrices::class)]
final class ShowTrendsControllerTest extends DashboardPageTestCase
{
    public function testAFreshInstallShowsTheWaitingOctopusAndNoTiles(): void
    {
        $page = $this->browser->request('GET', '/trends');

        self::assertResponseIsSuccessful();
        self::assertSame('No reviews yet', $page->filter('main .empty h2')->text());
        self::assertSame(1, preg_match('~octopus-waiting-light[^"]*\.webp$~', $page->filter('main .empty img.character')->attr('src') ?? ''));
        self::assertSelectorNotExists('.tiles');
        self::assertSelectorNotExists('.page-head .meta');
    }

    public function testWithoutTheSampleChangeTheOneActionOpensTheSetupPagesFirstReviewStep(): void
    {
        $action = $this->browser->request('GET', '/trends')->filter('main .empty .btn');

        self::assertSame(['Start the first review'], $action->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('/setup#step-first-review', $action->attr('href'));
    }

    public function testWithTheSampleChangeTheOneActionStartsTheFirstReviewAsTheSetupPageDoes(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn([SampleChange::PROJECT]);
        self::getContainer()->set(SampleChangeSource::class, new SampleChangeSource($tiers, '/repos/sample'));

        $page = $this->browser->request('GET', '/trends');

        $form = $page->filter('main .empty form.start-first-review');
        self::assertSame('/setup/first-review', $form->attr('action'));
        self::assertSame('post', $form->attr('method'));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        self::assertSame(['Start the first review'], $page->filter('main .empty .btn')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testTheTilesShowTheFiguresReadFromTheLedgerOverEveryProject(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->startRound('r-2', 'beta');
        $this->ledger->judgeOneEntryOf('r-1', 'F1', 'F2');
        $this->ledger->judgeFindings('r-2', ['F3' => 'act', 'F4' => 'defer']);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame([
            'Review rounds run' => '2',
            'P1 and P2 findings on act lists' => '2',
            'Caught by one reviewer only' => 'No data',
            'Median time from round start to verdict' => '0 s',
            'Cost per round' => 'No data',
        ], $this->tiles($page));
        self::assertSame('2 review rounds over every project', $page->filter('.page-head .meta span')->text());
    }

    public function testTheOneReviewerTileReadsNoDataForRoundsWithoutARecordOfWhoReportedWhatEvenWhenActEntriesHaveMerges(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->judgeOneEntryOf('r-1', 'F1', 'F2');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('No data', $this->tiles($page)['Caught by one reviewer only']);
        self::assertStringContainsString('"Caught by one reviewer only" counts only the rounds with a full record of which reviewer reported each finding.', $page->filter('.tiles-note')->text());
    }

    public function testTheOneReviewerTileShowsTheShareOfActEntriesOnlyOneReviewerReported(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->reportFindings('r-1', 'correctness', ['F1']);
        $this->ledger->reportFindings('r-1', 'security', ['F2']);
        $this->ledger->judgeOneEntryOf('r-1', 'F1', 'F2');
        $this->ledger->startRound('r-2', 'alpha');
        $this->ledger->reportFindings('r-2', 'correctness', ['F1']);
        $this->ledger->reportFindings('r-2', 'security', []);
        $this->ledger->judgeFindings('r-2', ['F1' => 'act']);
        $this->ledger->startRound('r-3', 'alpha');
        $this->ledger->judgeOneEntryOf('r-3', 'F1', 'F2');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('50%', $this->tiles($page)['Caught by one reviewer only']);
    }

    public function testAnUnreadableReportCannotTurnAJoinedEntryIntoAOneReviewerCatch(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->answer('r-1', 'correctness', 1);
        $this->ledger->answer('r-1', 'security', 1);
        $this->ledger->reportFindings('r-1', 'correctness', ['F1']);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerFindingsRecorded, '{"round":"r-1","reviewer":"r-1-security","findings":[7]}'));
        $this->ledger->judgeOneEntryOf('r-1', 'F1', 'F2');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('No data', $this->tiles($page)['Caught by one reviewer only']);
    }

    public function testTheCostTileIsTheMeanOverEveryPricedRound(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->startRound('r-2', 'alpha');
        $this->ledger->useTokens('r-1', 1000, 100);
        $this->ledger->useTokens('r-2', 3000, 300);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['2', '0.0060 USD'], [$this->tiles($page)['Review rounds run'], $this->tiles($page)['Cost per round']]);
    }

    public function testAFigureWithoutDataReadsNoDataNeverZero(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['1', 'No data', 'No data', 'No data', 'No data'], array_values($this->tiles($page)));
        self::assertCount(4, $page->filter('.tile[data-no-data]'));
    }

    public function testTheCostTileCarriesTheEstimatedLabelAndThePriceDate(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->useTokens('r-1', 1000, 100);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('0.0030 USD', $this->tiles($page)['Cost per round']);
        self::assertSame('estimated', $page->filter('.tile dt .figure-kind')->attr('data-kind'));
        self::assertStringStartsWith('Cost per round is the mean of the rounds with a known estimate, prices of 2026-11-13.', $page->filter('.tiles-note')->text());
    }

    public function testAReplayedReviewIsNamedInTheMetaLineAndTheChipLeavesItOut(): void
    {
        $this->givenALiveAndAReplayedRound();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['2 review rounds over every project', 'Includes the recorded demo review'], $page->filter('.page-head .meta span')->each(static fn (Crawler $fact): string => $fact->text()));
        $chip = $page->filter('.page-head .actions a.fchip');
        self::assertSame('Leave out the demo review', $chip->text());
        self::assertSame('/trends?demo=0', $chip->attr('href'));
    }

    public function testWithTheDemoLeftOutOnlyLiveRoundsCountAndTheChipCountsItAgain(): void
    {
        $this->givenALiveAndAReplayedRound();

        $page = $this->browser->request('GET', '/trends?demo=0');

        self::assertSame('1', $this->tiles($page)['Review rounds run']);
        self::assertSame(['1 review round over every project', 'Leaves out the recorded demo review'], $page->filter('.page-head .meta span')->each(static fn (Crawler $fact): string => $fact->text()));
        $chip = $page->filter('.page-head .actions a.fchip');
        self::assertSame('Count the demo review', $chip->text());
        self::assertSame('/trends', $chip->attr('href'));
    }

    public function testWithoutAReplayedReviewThereIsNoDemoChip(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends?demo=0');

        self::assertSelectorNotExists('.page-head .actions');
        self::assertSame(['1 review round over every project'], $page->filter('.page-head .meta span')->each(static fn (Crawler $fact): string => $fact->text()));
    }

    public function testTheRailListsTrendsAfterProjectsAndMarksItCurrentOnTheTrendsPage(): void
    {
        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['Waiting on you', 'Board', 'Projects', 'Trends'], $page->filter('.rail a.nav')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['trends'], $page->filter('.rail [aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
    }

    public function testTheTwoChartsShowTheirTitlesAndTakeawaysReadFromTheLedger(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['Which reviewer caught what', 'What the judge did'], $page->filter('.chart h2')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame([
            'The correctness reviewer found the most P1 findings, 1, and 0 of them no other reviewer found.',
            'The judge kept 1 of 3 findings and rejected 1.',
        ], $page->filter('.chart .takeaway')->each(static fn (Crawler $takeaway): string => $takeaway->text()));
        self::assertSame(['Correctness', 'Security'], $page->filter('#chart-catches figcaption')->each(static fn (Crawler $role): string => $role->text()));
    }

    public function testEachBarShowsItsCountAndTheShareFoundAloneAsItsDirectLabel(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['1 (0 alone)', '0 (0 alone)', '0 (0 alone)', '0 (0 alone)', '1 (0 alone)', '0 (0 alone)'], $page->filter('#chart-catches .c-label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame(['1', '1', '0', '1'], $page->filter('#chart-judge .c-label')->each(static fn (Crawler $label): string => $label->text()));
    }

    public function testEachChartsTableListsTheSameNumbersAsItsBars(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('Show as table', $page->filter('#chart-catches details.c-table summary')->text());
        self::assertSame(
            $page->filter('#chart-catches .c-label')->each(static fn (Crawler $label): string => $label->text()),
            $page->filter('#chart-catches tbody tr')->each(static fn (Crawler $row): string => sprintf('%s (%s alone)', $row->filter('td')->eq(2)->text(), $row->filter('td')->eq(3)->text())),
        );
        self::assertSame(
            ['Kept on the act list' => '1', 'Merged as duplicate' => '1', 'Deferred to a follow-up issue' => '0', 'Rejected' => '1'],
            array_column($page->filter('#chart-judge tbody tr')->each(static fn (Crawler $row): array => [$row->filter('td')->eq(0)->text(), $row->filter('td')->eq(1)->text()]), 1, 0),
        );
        self::assertSame(['1', '1', '0', '1'], $page->filter('#chart-judge .c-label')->each(static fn (Crawler $label): string => $label->text()));
    }

    public function testEveryBarIsReachedByKeyboardAndLabelsItsValueFirst(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $marks = $this->browser->request('GET', '/trends')->filter('.chart .c-hit');

        self::assertCount(10, $marks);
        self::assertSame(array_fill(0, 10, '0'), $marks->each(static fn (Crawler $mark): string => (string) $mark->attr('tabindex')));
        self::assertSame('1 P1 finding, Correctness reviewer, 0 found by it alone', $marks->first()->attr('aria-label'));
        self::assertSame('1 finding, Rejected', $marks->last()->attr('aria-label'));
        self::assertSame(array_fill(0, 10, 'chart'), $marks->each(static fn (Crawler $mark): string => (string) $mark->closest('[data-controller]')?->attr('data-controller')));
    }

    public function testAChartWithoutDataReadsNoDataAndDrawsNoBar(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['No data', 'No data'], $page->filter('.chart .takeaway[data-no-data]')->each(static fn (Crawler $takeaway): string => $takeaway->text()));
        self::assertSelectorNotExists('.chart svg');
        self::assertSelectorNotExists('.chart table');
    }

    public function testRoundsWithoutAReporterRecordLeaveTheReviewerChartWithoutDataWhileTheJudgeChartCounts(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->judgeFindings('r-1', ['F1' => 'act']);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('No data', $page->filter('#chart-catches .takeaway')->text());
        self::assertSame('The judge kept 1 of 1 findings and rejected 0.', $page->filter('#chart-judge .takeaway')->text());
    }

    private function givenAJudgedRoundWithTwoReporters(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        foreach (['correctness', 'security'] as $role) {
            $this->ledger->startReviewer('r-1', $role);
            $this->ledger->answer('r-1', $role, 1);
        }

        $this->ledger->reportFindings('r-1', 'correctness', ['F1']);
        $this->ledger->reportFindings('r-1', 'security', ['F2']);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, (string) json_encode([
            'round' => 'r-1',
            'act' => ['F1'],
            'follow_ups' => [],
            'findings' => [
                ['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => ['F2']],
                ['id' => 'F2', 'severity' => 'P2', 'text' => 'Text of F2.', 'merged' => []],
                ['id' => 'F3', 'severity' => 'P3', 'text' => 'Text of F3.', 'merged' => []],
            ],
        ])));
    }

    private function givenALiveAndAReplayedRound(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->startRound('r-2', 'demo-replay');
        $this->storeRoundAs('r-2', ReplayMark::Replay);
    }

    /**
     * @return array<string, string>
     */
    private function tiles(Crawler $page): array
    {
        $tiles = [];

        foreach ($page->filter('.tiles .tile') as $tile) {
            $crawler = new Crawler($tile);
            $label = $crawler->filter('dt')->getNode(0)?->firstChild->textContent ?? '';
            $tiles[trim($label)] = $crawler->filter('dd')->text();
        }

        return $tiles;
    }
}
