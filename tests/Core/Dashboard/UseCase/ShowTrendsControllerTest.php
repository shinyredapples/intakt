<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartScale;
use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Dashboard\Domain\ReviewerCatch;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Domain\ReviewsPerDay;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Dashboard\Infrastructure\LedgerQueuedRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowTrends\ShowTrendsController;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Tests\Support\FixedRoundEndings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowTrendsController::class)]
#[CoversClass(LedgerReviewPages::class)]
#[UsesClass(ReviewTrends::class)]
#[UsesClass(Board::class)]
#[UsesClass(UpNext::class)]
#[UsesClass(QueuedRound::class)]
#[UsesClass(LedgerQueuedRounds::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(PublishedBench::class)]
#[UsesClass(BenchHeadline::class)]
#[UsesClass(ReviewerCatches::class)]
#[UsesClass(ReviewerCatch::class)]
#[UsesClass(JudgeOutcomes::class)]
#[UsesClass(ReviewsPerDay::class)]
#[UsesClass(ChartBar::class)]
#[UsesClass(ChartScale::class)]
#[UsesClass(SampleChangeSource::class)]
#[UsesClass(ConfiguredPrices::class)]
final class ShowTrendsControllerTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    public function testAFreshInstallShowsTheWaitingOctopusAndNoTiles(): void
    {
        $page = $this->browser->request('GET', '/trends');

        self::assertResponseIsSuccessful();
        self::assertSame('No reviews yet', $page->filter('main .empty h2')->text());
        self::assertSame(1, preg_match('~octopus-waiting-light[^"]*\.webp$~', $page->filter('main .empty img.character')->attr('src') ?? ''));
        self::assertSame([], $this->tiles($page));
        self::assertCount(0, $page->filter('.tiles'));
    }

    public function testThePageNameStaysAHiddenHeadingAndTheBenchSentenceIsNotTheH1(): void
    {
        $this->publishedBench();
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['Trends'], $page->filter('main h1')->each(static fn (Crawler $h1): string => $h1->text()));
        self::assertSame('sr-only', $page->filter('main h1')->attr('class'));
        self::assertCount(1, $page->filter('main .bench-headline .bench-result'));
        self::assertCount(0, $page->filter('main h1 .bench-result'));
    }

    public function testTheHeadlineStatesTheBenchResultInWordsWithItsFalseAlarmsMeasuredAndRunDate(): void
    {
        $bench = $this->publishedBench();
        $this->ledger->startRound('r-1', 'alpha');

        $headline = $this->browser->request('GET', '/trends')->filter('.bench-headline');

        self::assertSame(sprintf('The review panel found %d of %d planted defects', $bench->found, $bench->planted), $headline->filter('.bench-result')->text());
        self::assertStringContainsString($bench->falseAlarms.(1 === $bench->falseAlarms ? ' false alarm' : ' false alarms').' in all', $headline->filter('.bench-meta')->text());
        if (null !== $bench->runs) {
            self::assertStringContainsString(' '.$bench->runs.(1 === $bench->runs ? ' run' : ' runs'), $headline->filter('.bench-meta')->text());
        }
        self::assertSame('measured', $headline->filter('.figure-kind')->attr('data-kind'));
        self::assertSame([$bench->ranOn, 'Run of '.$bench->ranOn], [$headline->filter('time')->attr('datetime'), $headline->filter('time')->text()]);
    }

    public function testABenchRunWithOneFalseAlarmNamesItInTheSingular(): void
    {
        self::getContainer()->set(PublishedBench::class, new PublishedBench(__DIR__.'/ShowTrends/a-bench-run-with-one-false-alarm.md'));

        $meta = $this->browser->request('GET', '/trends')->filter('.bench-headline .bench-meta');

        self::assertStringContainsString('1 false alarm in all', $meta->text());
    }

    public function testTheOctopusAppearsOncePerPageWithTheHeadlineAndOnceOnTheEmptyState(): void
    {
        $this->publishedBench();
        $empty = $this->browser->request('GET', '/trends');
        self::assertSame(1, $this->octopuses($empty));

        $this->ledger->startRound('r-1', 'alpha');
        $page = $this->browser->request('GET', '/trends');

        self::assertSame(1, $this->octopuses($page));
    }

    public function testEachTileShowsItsRoleCharacterAndItsVisibleCaption(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->givenAJudgedRoundWithTwoReporters();
        $this->ledger->useTokens('r-1', 1000, 100);

        $page = $this->browser->request('GET', '/trends');

        $shown = [];
        foreach ($page->filter('.tiles .tile') as $node) {
            $tile = new Crawler($node);
            $shown[(string) $tile->attr('data-tile')] = [
                1 === preg_match('~/roles/(code_reviewer|judge)-light-~', $tile->filter('img.character-light')->attr('src') ?? '', $role) ? $role[1] : '',
                $tile->filter('dd.tile-caption')->text(),
            ];
        }

        self::assertSame([
            'passes' => ['code_reviewer', 'Reviews run so far'],
            'to-fix' => ['judge', 'Serious, kept by the judge'],
            'alone' => ['code_reviewer', 'Findings no one else raised'],
            'verdict-time' => ['judge', 'Median, start to verdict'],
            'trends-cost' => ['judge', 'Mean over priced passes'],
        ], $shown);
    }

    public function testATileKeepsItsDecorativeImagesInsideADescription(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertCount(0, $page->filter('dl.tiles > div.tile > :not(dt):not(dd)'));
        self::assertCount(2, $page->filter('dl.tiles dd.tile-caption img.character'));
    }

    public function testThePassesTileExplainsAStandaloneReviewPassWithoutCallingItABuild(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $tile = $this->browser->request('GET', '/trends')->filter('[data-tile="passes"]');

        self::assertSame('1', $tile->filter('dd')->text());
        self::assertSame('Review passes started, whether run on their own or for a build.', $tile->filter('.term-tip')->text());
    }

    public function testWithoutAReadableBenchTableThereIsNoBenchSentenceAndNoDefectsFoundTileButTheOctopusStays(): void
    {
        self::getContainer()->set(PublishedBench::class, new PublishedBench($this->projectDir().'/no-such-readme.md'));
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['passes'], $page->filter('.tiles .tile')->each(static fn (Crawler $tile): string => (string) $tile->attr('data-tile')));
        self::assertCount(0, $page->filter('.bench-result'));
        self::assertSame(1, $this->octopuses($page));
        self::assertStringNotContainsString('Defects found', $page->text());
    }

    public function testTheTilesComeBeforeTheFirstChart(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['tiles', 'charts'], $page->filter('main .tiles, main .charts')->each(static fn (Crawler $block): string => (string) $block->attr('class')));
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
            'Passes' => '2',
            'To fix' => '2',
            'Time to verdict' => '0 s',
        ], $this->tiles($page));
        self::assertSelectorNotExists('.page-head .meta');
    }

    public function testTheOneReviewerTileIsLeftOutForRoundsWithoutARecordOfWhoReportedWhatEvenWhenActEntriesHaveMerges(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->judgeOneEntryOf('r-1', 'F1', 'F2');

        $page = $this->browser->request('GET', '/trends');

        self::assertArrayNotHasKey('One reviewer only', $this->tiles($page));
        self::assertSelectorNotExists('.tiles-note');
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

        self::assertSame('50%', $this->tiles($page)['One reviewer only']);
    }

    public function testTheOneReviewerTooltipNamesTheFindingsKeptToFixOverPassesWithAFullReporterRecord(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->reportFindings('r-1', 'correctness', ['F1']);
        $this->ledger->reportFindings('r-1', 'security', []);
        $this->ledger->judgeFindings('r-1', ['F1' => 'act']);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(
            'Share of the findings kept to fix that only one reviewer of the pass reported, over passes with a full record of who reported what.',
            $page->filter('[data-tile="alone"] .term-tip')->text(),
        );
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

        self::assertArrayNotHasKey('One reviewer only', $this->tiles($page));
    }

    public function testTheCostTileIsTheMeanOverEveryPricedRound(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->startRound('r-2', 'alpha');
        $this->ledger->useTokens('r-1', 1000, 100);
        $this->ledger->useTokens('r-2', 3000, 300);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['2', '0.0060 USD'], [$this->tiles($page)['Passes'], $this->tiles($page)['Cost per pass']]);
    }

    public function testATileWhoseFigureHasNoDataIsLeftOutNeverShownAsZero(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['Passes' => '1'], $this->tiles($page));
        self::assertSelectorNotExists('.tile[data-no-data]');
    }

    public function testTheCostTileCarriesTheEstimatedLabelAndThePriceDate(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->useTokens('r-1', 1000, 100);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('0.0030 USD', $this->tiles($page)['Cost per pass']);
        self::assertSame('estimated', $page->filter('[data-tile="trends-cost"] dt .figure-kind')->attr('data-kind'));
        self::assertSame('Mean of the passes with a cost estimate, prices of 2026-11-13.', $page->filter('[data-tile="trends-cost"] .term-tip')->text());
    }

    public function testAReplayedReviewIsCountedAndThePressedToggleLeavesItOut(): void
    {
        $this->givenALiveAndAReplayedRound();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('2', $this->tiles($page)['Passes']);
        $chip = $page->filter('.page-head form.actions button.demo-toggle');
        self::assertSame(['Demo review', 'true'], [$chip->text(), $chip->attr('aria-pressed')]);
        self::assertSame(['get', '/trends'], [$page->filter('.page-head form.actions')->attr('method'), $page->filter('.page-head form.actions')->attr('action')]);
        self::assertSame(['demo' => '0'], $page->filter('.page-head form.actions')->form()->getValues());
    }

    public function testWithTheDemoLeftOutOnlyLiveRoundsCountAndTheUnpressedToggleCountsItAgain(): void
    {
        $this->givenALiveAndAReplayedRound();

        $page = $this->browser->request('GET', '/trends?demo=0');

        self::assertSame('1', $this->tiles($page)['Passes']);
        $chip = $page->filter('.page-head form.actions button.demo-toggle');
        self::assertSame(['Demo review', 'false'], [$chip->text(), $chip->attr('aria-pressed')]);
        self::assertSame([], $page->filter('.page-head form.actions')->form()->getValues());
    }

    public function testWithoutAReplayedReviewThereIsNoDemoToggle(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $this->browser->request('GET', '/trends?demo=0');

        self::assertSelectorNotExists('.page-head .actions');
    }

    public function testTheRailListsTrendsAfterYouAndMarksItCurrentOnTheTrendsPage(): void
    {
        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['Now', 'You', 'Trends', 'Projects'], $page->filter('.rail a.nav')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['trends'], $page->filter('.rail [aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
    }

    public function testTheTwoChartsShowTheirTitlesAndTakeawaysReadFromTheLedger(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame([['Catches by reviewer', 'counted'], ['Checked findings', 'counted']], $page->filter('#chart-catches h2, #chart-judge h2')->each(static fn (Crawler $title): array => [trim((string) $title->getNode(0)?->firstChild?->textContent), (string) $title->filter('.figure-kind')->attr('data-kind')]));
        self::assertSame([
            'The correctness reviewer found the most P1 findings, 1, and 0 of them no other reviewer found.',
            'The judge kept 1 of 3 findings and rejected 1.',
        ], $page->filter('#chart-catches > .takeaway, #chart-judge > .takeaway')->each(static fn (Crawler $takeaway): string => $takeaway->text()));
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

        self::assertCount(0, $page->filter('details'));
        self::assertStringNotContainsString('Show as table', $page->text());
        self::assertCount(2, $page->filter('main table.sr-only'));
        self::assertSame(
            $page->filter('#chart-catches .c-label')->each(static fn (Crawler $label): string => $label->text()),
            $page->filter('#chart-catches tbody tr')->each(static fn (Crawler $row): string => sprintf('%s (%s alone)', $row->filter('td')->eq(2)->text(), $row->filter('td')->eq(3)->text())),
        );
        self::assertSame(
            ['Kept to fix' => '1', 'Merged as duplicate' => '1', 'Deferred to a follow-up issue' => '0', 'Rejected' => '1'],
            array_column($page->filter('#chart-judge tbody tr')->each(static fn (Crawler $row): array => [$row->filter('td')->eq(0)->text(), $row->filter('td')->eq(1)->text()]), 1, 0),
        );
        self::assertSame(['1', '1', '0', '1'], $page->filter('#chart-judge .c-label')->each(static fn (Crawler $label): string => $label->text()));
    }

    public function testEveryBarIsReachedByKeyboardAndLabelsItsValueFirst(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $marks = $this->browser->request('GET', '/trends')->filter('#chart-catches .c-hit, #chart-judge .c-hit');

        self::assertCount(10, $marks);
        self::assertSame(array_fill(0, 10, '0'), $marks->each(static fn (Crawler $mark): string => (string) $mark->attr('tabindex')));
        self::assertSame('1 P1 finding, Correctness reviewer, 0 found by it alone', $marks->first()->attr('aria-label'));
        self::assertSame('1 finding, Rejected', $marks->last()->attr('aria-label'));
        self::assertSame(array_fill(0, 10, 'chart'), $marks->each(static fn (Crawler $mark): string => (string) $mark->closest('[data-controller]')?->attr('data-controller')));
    }

    // WCAG 1.4.13: a tip shown by the pointer alone is dismissed without moving the pointer or the focus, so Escape is heard at page scope, not on the bar.
    public function testEscapeAnywhereOnThePageDismissesEachChartsTip(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(array_fill(0, 2, 'keydown.esc@window->chart#dismiss'), $page->filter('[data-controller="chart"]')->each(static fn (Crawler $chart): string => (string) $chart->attr('data-action')));
        self::assertSame([], array_filter($page->filter('#chart-catches .c-hit, #chart-judge .c-hit')->each(static fn (Crawler $mark): string => (string) $mark->attr('data-action')), static fn (string $action): bool => str_contains($action, 'keydown')));
    }

    public function testAChartWithoutDataIsLeftOutAndDrawsNoBar(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        $page = $this->browser->request('GET', '/trends');

        self::assertSelectorNotExists('.chart');
        self::assertSelectorNotExists('.chart table');
        self::assertCount(0, $page->filter('.chart-svg'));
    }

    public function testRoundsWithoutAReporterRecordLeaveTheReviewerChartOutWhileTheJudgeChartCounts(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->judgeFindings('r-1', ['F1' => 'act']);

        $page = $this->browser->request('GET', '/trends');

        self::assertSelectorNotExists('#chart-catches');
        self::assertSame('The judge kept 1 of 1 findings and rejected 0.', $page->filter('#chart-judge .takeaway')->text());
    }

    public function testAnEntryRaisedByItsMergeCountsForItsReviewerUnderTheSeverityItReported(): void
    {
        $this->givenTwoReportersWhoseEntriesMerged(['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => ['F2']], ['F1' => 'P2'], ['F2' => 'P1']);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('The security reviewer found the most P1 findings, 1, and 0 of them no other reviewer found.', $page->filter('#chart-catches .takeaway')->text());
        self::assertSame(['0 (0 alone)', '1 (0 alone)', '0 (0 alone)'], $page->filter('#chart-catches figure')->first()->filter('.c-label')->each(static fn (Crawler $label): string => $label->text()));
    }

    // Each reporter's own row gives the severity, because one lookup per finding id credited two repeat reporters with one severity (#2981, decision 4).
    public function testTwoReportersRepeatingOneFindingAtDifferentSeveritiesEachCountUnderTheirOwn(): void
    {
        $this->givenTwoReporters(['F1' => 'P2'], ['F1' => 'P1']);
        $this->judgeDone(['act' => ['F1'], 'findings' => [['id' => 'F1', 'severity' => 'P2', 'text' => 'The issue persists.', 'merged' => []]], 'fallback' => false]);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['0 (0 alone)', '1 (0 alone)', '0 (0 alone)'], $page->filter('#chart-catches figure')->first()->filter('.c-label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame(['1 (0 alone)', '0 (0 alone)', '0 (0 alone)'], $page->filter('#chart-catches figure')->last()->filter('.c-label')->each(static fn (Crawler $label): string => $label->text()));
    }

    public function testReporterRowsWrittenBeforeTheirSeveritiesWereRecordedLeaveTheReviewerChartOut(): void
    {
        $this->givenTwoReportersWithoutSeverities();
        $this->judgeDone(['act' => ['F1'], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => []], ['id' => 'F2', 'severity' => 'P1', 'text' => 'Text of F2.', 'merged' => []]], 'fallback' => false]);

        $this->browser->request('GET', '/trends');

        self::assertSelectorNotExists('#chart-catches');
    }

    // Decision 5 of #2981: the fallback for an answer that cannot be read leaves a P3 unmarked, which is not the judge's rejection.
    public function testAFallbackRoundsUnmarkedP3AddsNothingToRejected(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->judgeFindings('r-1', ['F1' => 'reject']);
        $this->ledger->startRound('r-2', 'alpha');
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, (string) json_encode(['round' => 'r-2', 'act' => ['F1'], 'follow_ups' => [], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.'], ['id' => 'F2', 'severity' => 'P3', 'text' => 'Text of F2.']], 'fallback' => true])));

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('The judge kept 0 of 1 findings and rejected 1.', $page->filter('#chart-judge .takeaway')->text());
    }

    public function testAJudgeRowWrittenBeforeTheFallbackWasRecordedLeavesTheJudgeChartOut(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"r-1","act":[],"follow_ups":[],"findings":[{"id":"F1","severity":"P3","text":"A finding"}]}'));

        $this->browser->request('GET', '/trends');

        self::assertSelectorNotExists('#chart-judge');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function marksMissingFromTheFindings(): iterable
    {
        yield 'an act id' => [['act' => ['F1'], 'findings' => []]];
        yield 'a follow-up id' => [['follow_ups' => ['F1'], 'findings' => []]];
        yield 'a merged id' => [['act' => ['F1'], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => ['F2']]]]];
        yield 'an unreadable entry' => [['act' => ['F1'], 'findings' => [null]]];
    }

    // A round counts only when every id the judge marked is among its recorded findings, because matching entry counts read "kept 1 of 1" with no finding recorded (#2981, decision 6).
    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('marksMissingFromTheFindings')]
    public function testAMarkedIdMissingFromTheFindingsLeavesBothChartsOut(array $row): void
    {
        $this->givenTwoReporters(['F1' => 'P1'], []);
        $this->judgeDone($row + ['fallback' => false]);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame([], $page->filter('#chart-catches, #chart-judge')->each(static fn (Crawler $chart): string => (string) $chart->attr('id')));
    }

    public function testAnUnreadableFindingLeavesBothChartsOutRatherThanCountingTheRest(): void
    {
        $this->givenTwoReportersWhoseEntriesMerged(['id' => 'F1', 'severity' => 'unknown', 'text' => 'Text of F1.', 'merged' => ['F2']]);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame([], $page->filter('#chart-catches, #chart-judge')->each(static fn (Crawler $chart): string => (string) $chart->attr('id')));
    }

    public function testThePageLoadsTheScriptsThatStartTheTooltipController(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertCount(1, $page->filter('script[type="importmap"]'));
        self::assertCount(2, $page->filter('[data-controller="chart"]'));
    }

    public function testTheTrendsPageLeavesTheDayChartOutWhenReviewsRanOnFewerThanThreeDays(): void
    {
        $this->ledger->startRoundAt('r-1', new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-2', new DateTimeImmutable('2026-10-27T10:00:00+00:00'));

        $page = $this->browser->request('GET', '/trends');

        self::assertSelectorNotExists('#chart-per-day');
        self::assertSelectorNotExists('.chart-svg[aria-label*="per day"]');
        self::assertStringNotContainsString('Shows once', $page->text());
    }

    public function testWithReviewsOnThreeDaysTheTrendsPageDrawsChartThreeAndItsTableListsTheSameNumbers(): void
    {
        $this->givenReviewsOnThreeDaysWithOnePricedRound();

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('Passes and cost per day', $page->filter('#chart-per-day h2')->text());
        self::assertSame(['counted', 'estimated'], $page->filter('#chart-per-day figcaption .figure-kind')->each(static fn (Crawler $kind): string => (string) $kind->attr('data-kind')));
        self::assertCount(2, $page->filter('#chart-per-day svg.chart-svg'));
        $labels = $page->filter('#chart-per-day .c-label')->each(static fn (Crawler $label): string => $label->text());
        self::assertSame(['1', '2', '1', 'No data', '0.0030', 'No data'], $labels);
        $rows = $page->filter('#chart-per-day tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame([['Oct 26', '1', 'No data'], ['Oct 27', '2', '0.0030'], ['Oct 28', '1', 'No data']], $rows);
        self::assertSame(array_merge(array_column($rows, 1), array_column($rows, 2)), $labels);
    }

    public function testEveryChartThreeMarkIsKeyboardFocusableAndLabelsItsValueFirst(): void
    {
        $this->givenReviewsOnThreeDaysWithOnePricedRound();

        $marks = $this->browser->request('GET', '/trends')->filter('#chart-per-day .c-hit');

        self::assertCount(6, $marks);
        self::assertSame(array_fill(0, 6, '0'), $marks->each(static fn (Crawler $mark): string => (string) $mark->attr('tabindex')));
        self::assertSame(['1 round, Oct 26', '2 rounds, Oct 27', '1 round, Oct 28', 'No data, Oct 26, no cost estimate', '0.0030 USD, estimated, Oct 27', 'No data, Oct 28, no cost estimate'], $marks->each(static fn (Crawler $mark): string => (string) $mark->attr('aria-label')));
        self::assertCount(4, $this->browser->request('GET', '/trends')->filter('#chart-per-day path.c-bar'));
    }

    public function testWithReviewsOnTwentyDaysEveryBarKeepsAPositiveWidthAndTheAxisLabelsThinWhileTheTableNamesEveryDay(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));

        $first = new DateTimeImmutable('2026-10-01T10:00:00+00:00');

        for ($day = 1; $day <= 20; ++$day) {
            $this->ledger->startRoundAt('r-'.$day, $first->modify(sprintf('+%d days', $day - 1)));
            $this->ledger->useTokens('r-'.$day, 1000, 100);
        }

        $page = $this->browser->request('GET', '/trends');

        $paths = $page->filter('#chart-per-day path.c-bar')->each(static fn (Crawler $path): string => (string) $path->attr('d'));
        self::assertCount(40, $paths);

        foreach ($paths as $path) {
            if (1 !== preg_match('/^M([\d.]+),.*? H([\d.]+) /', $path, $edges)) {
                self::fail('Not a bar path: '.$path);
            }

            self::assertGreaterThan((float) $edges[1], (float) $edges[2], $path);
        }

        $axis = $page->filter('#chart-per-day svg')->first()->filter('text.c-axis[text-anchor="middle"]')->each(static fn (Crawler $label): string => $label->text());
        self::assertSame(['Oct 1', 'Oct 5', 'Oct 9', 'Oct 13', 'Oct 17'], $axis);
        self::assertCount(20, $page->filter('#chart-per-day tbody tr'));
        self::assertCount(40, $page->filter('#chart-per-day .c-hit'));
    }

    public function testLeavingOutTheDemoAlsoLeavesItsDaysOutOfChartThree(): void
    {
        $this->ledger->startRoundAt('r-1', new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-2', new DateTimeImmutable('2026-10-27T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-3', new DateTimeImmutable('2026-10-28T10:00:00+00:00'));
        $this->storeRoundAs('r-3', ReplayMark::Replay);

        self::assertCount(1, $this->browser->request('GET', '/trends')->filter('#chart-per-day'));
        self::assertCount(0, $this->browser->request('GET', '/trends?demo=0')->filter('#chart-per-day'));
    }

    public function testWithPerDayDataAndNoPricedDayTheTitleReadsPassesPerDayAndTheCostChartAndColumnAreLeftOut(): void
    {
        $this->ledger->startRoundAt('r-1', new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-2', new DateTimeImmutable('2026-10-27T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-3', new DateTimeImmutable('2026-10-28T10:00:00+00:00'));

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('Passes per day', $page->filter('#chart-per-day h2')->text());
        self::assertCount(1, $page->filter('#chart-per-day svg.chart-svg'));
        self::assertSame(['Day', 'Passes'], $page->filter('#chart-per-day thead th')->each(static fn (Crawler $head): string => $head->text()));
        self::assertSame([['Oct 26', '1'], ['Oct 27', '1'], ['Oct 28', '1']], $page->filter('#chart-per-day tbody tr')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text())));
        self::assertSelectorNotExists('#chart-per-day .figure-kind[data-kind="estimated"]');
    }

    public function testEachChartCarriesItsLegendAndItsTakeawayForScreenReadersOnly(): void
    {
        $this->givenAJudgedRoundWithTwoReporters();

        $page = $this->browser->request('GET', '/trends');

        self::assertCount(1, $page->filter('#chart-catches .chart-legend'));
        self::assertSame(['Found by one reviewer only', 'Also found by others'], $page->filter('#chart-catches .chart-legend > span')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSelectorNotExists('#chart-catches .chart-legend .term');
        self::assertSame(['sr-only', 'sr-only'], $page->filter('#chart-catches > .takeaway, #chart-judge > .takeaway')->each(static fn (Crawler $takeaway): string => (string) preg_replace('~^takeaway ~', '', (string) $takeaway->attr('class'))));
        self::assertSelectorNotExists('.chart-key');
    }

    public function testAReviewerWhoseBarsAreAllZeroHasNoSmallChartWhileTheOthersStay(): void
    {
        $this->givenTwoReporters(['F1' => 'P1'], []);
        $this->judgeDone(['act' => ['F1'], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => []]], 'fallback' => false]);

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['Correctness'], $page->filter('#chart-catches figcaption')->each(static fn (Crawler $role): string => $role->text()));
        self::assertSelectorNotExists('#chart-catches.wide');
        self::assertCount(1, $page->filter('#chart-catches .multiples.one'));
    }

    public function testAChartWhoseFiguresAreAllZeroIsLeftOut(): void
    {
        $this->givenTwoReporters(['F1' => 'P1'], ['F1' => 'P1']);
        $this->judgeDone(['act' => [], 'findings' => [], 'fallback' => false]);

        $this->browser->request('GET', '/trends');

        self::assertSelectorNotExists('#chart-judge');
    }

    public function testUpNextListsARoundWithItsReviewersAsInProgressAndLinksItsReviewPage(): void
    {
        $this->ledger->startRoundOf('feat/a', 'r-1', 2);
        $this->ledger->startReviewer('r-1', 'correctness');
        $this->ledger->startReviewer('r-1', 'security');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['In progress'], $page->filter('#up-next h3')->each(static fn (Crawler $title): string => $title->text()));
        $link = $page->filter('#up-next a.review-link');
        self::assertSame('/reviews/r-1', $link->attr('href'));
        self::assertSame(['feat/a', 'alpha', 'Round 2', 'Correctness, Security'], $link->filter('.review-title, .review-facts > span')->each(static fn (Crawler $part): string => $part->text()));
    }

    public function testUpNextListsARoundNoReviewerStartedAsWaitingToStart(): void
    {
        $this->ledger->startRoundOf('feat/a', 'r-1', 1);
        $this->ledger->startRoundOf('feat/b', 'r-2', 1);
        $this->ledger->startReviewer('r-2', 'correctness');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame(['In progress', 'Waiting to start'], $page->filter('#up-next h3')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['/reviews/r-2', '/reviews/r-1'], $page->filter('#up-next a.review-link')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame('No reviewer has started yet', $page->filter('#up-next ul')->last()->filter('.review-facts > span')->last()->text());
    }

    public function testUpNextReadsNothingRunningOrQueuedOnceEveryRoundIsJudged(): void
    {
        $this->ledger->startRound('r-1', 'alpha');
        $this->ledger->judgeRound('r-1');

        $page = $this->browser->request('GET', '/trends');

        self::assertSame('Up next', $page->filter('#up-next h2')->text());
        self::assertSame('Nothing running or queued.', $page->filter('#up-next p')->text());
        self::assertCount(0, $page->filter('#up-next a'));
    }

    public function testAFreshInstallsUpNextReadsNothingRunningOrQueued(): void
    {
        self::assertSame('Nothing running or queued.', $this->browser->request('GET', '/trends')->filter('#up-next p')->text());
    }

    public function testUpNextKeepsTheDemoRoundWhenTheFiguresLeaveItOutBecauseTheBoardShowsIt(): void
    {
        $this->givenALiveAndAReplayedRound();

        self::assertCount(2, $this->browser->request('GET', '/trends?demo=0')->filter('#up-next a.review-link'));
    }

    public function testUpNextListsTheBoardsInReviewRoundsAndLeavesOutTheJudgedOne(): void
    {
        $this->ledger->startRoundOf('feat/a', 'r-1', 1);
        $this->ledger->startReviewer('r-1', 'correctness');
        $this->ledger->startRoundOf('feat/b', 'r-2', 1);
        $this->ledger->startRoundOf('feat/c', 'r-3', 1);
        $this->ledger->judgeRound('r-3');

        $board = $this->browser->request('GET', '/?board')->filter('#col-in-review a[href^="/reviews/"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $upNext = $this->browser->request('GET', '/trends')->filter('#up-next a.review-link')->each(static fn (Crawler $link): string => (string) $link->attr('href'));

        self::assertSame(['/reviews/r-1', '/reviews/r-2'], $upNext);
        self::assertEqualsCanonicalizing(array_unique($board), $upNext);
    }

    public function testUpNextLeavesOutARoundThatEndedWithoutTheJudgesCallWhileTheBoardKeepsItInReview(): void
    {
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, 'The judge failed.')));
        $this->ledger->startRoundOf('feat/a', 'r-1', 1);
        $this->ledger->startReviewer('r-1', 'correctness');

        $board = $this->browser->request('GET', '/?board')->filter('#col-in-review a[href="/reviews/r-1"]');
        $upNext = $this->browser->request('GET', '/trends')->filter('#up-next');

        self::assertNotCount(0, $board);
        self::assertSame(['Nothing running or queued.'], $upNext->filter('p')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testUpNextListsARoundTheWatcherQueuedAsWaitingToStartWithoutALink(): void
    {
        $this->ledger->queueRoundOf('alpha', '7', 'abc123');

        $upNext = $this->browser->request('GET', '/trends')->filter('#up-next');

        self::assertSame(['Waiting to start'], $upNext->filter('h3')->each(static fn (Crawler $title): string => $title->text()));
        self::assertCount(0, $upNext->filter('a.review-link'));
        self::assertCount(0, $upNext->filter('p'));
        self::assertSame(['Pull request 7', 'alpha', 'Queued, not started yet'], $upNext->filter('.review-line .review-title, .review-line .review-facts > span')->each(static fn (Crawler $part): string => $part->text()));
    }

    public function testUpNextLinksAQueuedRoundOnceItsReviewStarted(): void
    {
        $round = $this->ledger->queueRoundOf('alpha', '7', 'abc123');
        $this->ledger->startRound($round, 'alpha');

        $upNext = $this->browser->request('GET', '/trends')->filter('#up-next');

        self::assertSame(['/reviews/'.$round], $upNext->filter('a.review-link')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertCount(0, $upNext->filter('.review-line'));
    }

    // The watcher's normal order: RoundStarts opens the review before it writes the queue line.
    public function testUpNextLinksARoundWhoseReviewOpenedBeforeItsQueueLineOnceUnderWaitingToStart(): void
    {
        $this->ledger->startRoundOf('feat/a', 'r-1', 1);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::RoundQueued, '{"project":"alpha","change":"7","head":"abc123","round":"r-1"}'));

        $upNext = $this->browser->request('GET', '/trends')->filter('#up-next');

        self::assertSame(['Waiting to start'], $upNext->filter('h3')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['/reviews/r-1'], $upNext->filter('a.review-link')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertCount(0, $upNext->filter('div.review-line'));
        self::assertCount(0, $upNext->filter('p'));
    }

    public function testUpNextLeavesOutAQueuedRoundOnceANewerWatcherLineReplacedIt(): void
    {
        $this->ledger->queueRoundOf('alpha', '7', 'abc123');
        $this->ledger->forgetHead('alpha', '7', 'abc123');

        self::assertSame('Nothing running or queued.', $this->browser->request('GET', '/trends')->filter('#up-next p')->text());
    }

    public function testUpNextNamesARoundWithoutARecordedChangeAsUnnamed(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        self::assertSame('Unnamed change', $this->browser->request('GET', '/trends')->filter('#up-next .review-title')->text());
    }

    public function testUpNextKeepsARoundWhoseReviewersAllAnsweredInProgressUntilItIsJudged(): void
    {
        $this->givenTwoAnsweringReviewers();

        $upNext = $this->browser->request('GET', '/trends')->filter('#up-next');

        self::assertSame(['In progress'], $upNext->filter('h3')->each(static fn (Crawler $title): string => $title->text()));
        self::assertCount(1, $upNext->filter('a.review-link'));
    }

    private function givenReviewsOnThreeDaysWithOnePricedRound(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'));
        $this->ledger->startRoundAt('r-1', new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-2', new DateTimeImmutable('2026-10-27T10:00:00+00:00'));
        $this->ledger->startRoundAt('r-3', new DateTimeImmutable('2026-10-27T15:00:00+00:00'));
        $this->ledger->startRoundAt('r-4', new DateTimeImmutable('2026-10-28T10:00:00+00:00'));
        $this->ledger->useTokens('r-2', 1000, 100);
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $correctness
     * @param array<string, string> $security
     */
    private function givenTwoReportersWhoseEntriesMerged(array $entry, array $correctness = ['F1' => 'P1'], array $security = ['F2' => 'P1']): void
    {
        $this->givenTwoReporters($correctness, $security);
        $this->judgeDone(['act' => ['F1'], 'findings' => [$entry, ['id' => 'F2', 'severity' => 'P1', 'text' => 'Text of F2.', 'merged' => []]], 'fallback' => false]);
    }

    private function givenAJudgedRoundWithTwoReporters(): void
    {
        $this->givenTwoReporters(['F1' => 'P1'], ['F2' => 'P2']);
        $this->judgeDone([
            'act' => ['F1'],
            'findings' => [
                ['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => ['F2']],
                ['id' => 'F2', 'severity' => 'P2', 'text' => 'Text of F2.', 'merged' => []],
                ['id' => 'F3', 'severity' => 'P3', 'text' => 'Text of F3.', 'merged' => []],
            ],
            'fallback' => false,
        ]);
    }

    /**
     * @param array<string, string> $correctness
     * @param array<string, string> $security
     */
    private function givenTwoReporters(array $correctness, array $security): void
    {
        $this->givenTwoAnsweringReviewers();
        $this->ledger->reportRatedFindings('r-1', 'correctness', $correctness);
        $this->ledger->reportRatedFindings('r-1', 'security', $security);
    }

    private function givenTwoReportersWithoutSeverities(): void
    {
        $this->givenTwoAnsweringReviewers();
        $this->ledger->reportFindings('r-1', 'correctness', ['F1']);
        $this->ledger->reportFindings('r-1', 'security', ['F2']);
    }

    private function givenTwoAnsweringReviewers(): void
    {
        $this->ledger->startRound('r-1', 'alpha');

        foreach (['correctness', 'security'] as $role) {
            $this->ledger->startReviewer('r-1', $role);
            $this->ledger->answer('r-1', $role, 1);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function judgeDone(array $row): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, (string) json_encode(array_merge(['round' => 'r-1', 'act' => [], 'follow_ups' => []], $row))));
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
            $label = $crawler->filter('dt .term')->getNode(0)?->firstChild->textContent ?? '';
            $tiles[trim($label)] = $crawler->filter('dd')->text();
        }

        return $tiles;
    }

    private function octopuses(Crawler $page): int
    {
        return $page->filter('main img.character-light[src*="/mascot/"]')->count();
    }

    private function publishedBench(): BenchHeadline
    {
        $bench = new PublishedBench($this->projectDir().'/docs/bench.md')->headline();
        self::assertNotNull($bench, 'The bench page has no readable review bench table.');

        return $bench;
    }

    private function projectDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir');
    }
}
