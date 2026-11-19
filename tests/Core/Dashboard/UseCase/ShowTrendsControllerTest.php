<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\ShowTrends\ShowTrendsController;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\SampleChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowTrendsController::class)]
#[CoversClass(LedgerReviewPages::class)]
#[UsesClass(ReviewTrends::class)]
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
            'Caught by one reviewer only' => '50%',
            'Median time from round start to verdict' => '0 s',
            'Cost per round' => 'No data',
        ], $this->tiles($page));
        self::assertSame('2 review rounds over every project', $page->filter('.page-head .meta span')->text());
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
        self::assertSame('Cost per round is the mean of the rounds with a known estimate, prices of 2026-11-13.', $page->filter('.tiles-note')->text());
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

        self::assertSame(['Board', 'Projects', 'Trends'], $page->filter('.rail a.nav')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['trends'], $page->filter('.rail [aria-current="page"]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
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
