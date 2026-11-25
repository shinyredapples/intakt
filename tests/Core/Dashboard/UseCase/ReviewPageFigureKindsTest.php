<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Platform\Contract\FigureKind;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(FigureKind::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(SessionSummary::class)]
final class ReviewPageFigureKindsTest extends DashboardPageTestCase
{
    public function testAReviewOfThreeJudgedRoundsCarriesOneLabelInTheSummaryAndOnePerTableOfTheNewestRound(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();
        $this->givenAJudgedRound('round-2', 2);
        $this->givenAJudgedRound('round-3', 3);

        $crawler = $this->browser->request('GET', '/reviews/round-3/record');

        self::assertSame(
            ['kind-summary-cost', 'kind-round-round-3-findings', 'kind-round-round-3-cost'],
            $crawler->filter('.figure-kind')->each(static fn (Crawler $label): ?string => $label->attr('aria-describedby')),
        );
    }

    public function testTheTablesOfAnOlderRoundCarryNoLabel(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();
        $this->givenAJudgedRound('round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['Findings', 'Cost', 0], [
            $crawler->filter('#round-round-1 table.reviewers thead th.num')->text(),
            $crawler->filter('#round-round-1 table.tokens thead th')->last()->text(),
            $crawler->filter('#round-round-1 .figure-kind')->count(),
        ]);
    }

    public function testTheSummaryLeavesTheRoundCountAndTheTokenTotalUnlabeled(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('alphafeat/thing 1 round 1,300 tokens', $this->wordsOf($crawler->filter('.page-head p.meta'), before: ' Cost:'));
    }

    public function testTheSummaryCarriesOneLabelTheEstimateOfTheReviewsCost(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['estimated'], $crawler->filter('.page-head p.meta .figure-kind')->each(static fn (Crawler $label): ?string => $label->attr('data-kind')));
    }

    public function testNoTokenCountOfARoundCarriesALabel(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $tokens = $crawler->filter('#round-round-1 table.tokens tbody td.num');
        self::assertSame(['1000', '0', '0', '0', '300', '1300'], $tokens->each(static fn (Crawler $cell): string => $cell->html()));
    }

    public function testTheReviewCostIsMarkedEstimatedWithTheDayOfItsPrices(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Cost: 0.0050 USD estimated, prices of 2026-11-13', $this->wordsOf($crawler->filter('.page-head p.meta > span:nth-child(5)')));
    }

    public function testARoundsCostKeepsTheDayOfItsPricesAndLeavesItsLabelToTheColumnHead(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $cost = $crawler->filter('#round-round-1 table.tokens tbody td:last-child');
        self::assertSame(['0.0050 USD, prices of 2026-11-13', 0], [$cost->text(), $cost->filter('.figure-kind')->count()]);
    }

    public function testACostWithoutAPriceDateSaysTheDateIsUnknown(): void
    {
        $this->givenPricesOf(null);
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('0.0050 USD, price date unknown', $crawler->filter('#round-round-1 table.tokens tbody td:last-child')->text());
    }

    public function testAnUnknownCostCarriesNoLabelAndNoPriceDate(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('unknown, no price for model-a', $crawler->filter('#round-round-1 table.tokens tbody td:last-child')->text());
        self::assertSame('Cost: unknown, no price for model-a', $this->wordsOf($crawler->filter('.page-head p.meta > span:nth-child(5)')));
    }

    public function testATokensTableWhoseCostIsUnknownLeavesItsCostHeadUnlabeled(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Cost', 0], [$crawler->filter('#round-round-1 table.tokens thead th')->last()->text(), $crawler->filter('#round-round-1 table.tokens .figure-kind')->count()]);
    }

    public function testTheTokensTableMarksOnlyItsCostColumnEstimatedOnceInItsHead(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $kinds = $crawler->filter('#round-round-1 table.tokens th')->each(static fn (Crawler $head): array => $head->filter('.figure-kind')->each(static fn (Crawler $label): ?string => $label->attr('data-kind')));
        self::assertSame([[], [], [], [], [], [], ['estimated']], $kinds);
        self::assertSame(1, $crawler->filter('#round-round-1 table.tokens .figure-kind')->count());
    }

    public function testTheSessionSummaryShowsThisRoundsAndTheReviewsCostWithoutALabel(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenARunningRoundWhoseSessionCosts(Cost::inUsd(0.1, '2026-11-13'));

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(
            [['0.1000 USD, prices of 2026-11-13', 0], ['0.0050 USD, prices of 2026-11-13', 0]],
            $crawler->filter('.ls-cost dd')->each(static fn (Crawler $cost): array => [$cost->text(), $cost->filter('.figure-kind')->count()]),
        );
    }

    public function testAStreamedRoundCostKeepsItsPriceDateWithoutALabel(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $stream = $twig->render('dashboard/stream/summary_changed.stream.html.twig', ['round' => 'round-1', 'summary' => new SessionSummary([], Cost::inUsd(0.9, '2026-11-13'), new Paths(), [], [], [], false)]);

        $cost = new Crawler($stream)->filter('turbo-stream[target="round-round-1-summary-cost"]');
        self::assertSame('0.9000 USD, prices of 2026-11-13', new Crawler($cost->filter('template')->html())->text());
    }

    public function testEveryTooltipOnThePageHasItsOwnId(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenARunningRoundWhoseSessionCosts(Cost::inUsd(0.1, '2026-11-13'));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));
        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    public function testTheFindingCountOfAFoldedRoundCarriesNoLabel(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $count = $crawler->filter('#round-round-1 details.ev > summary .small')->last();
        self::assertSame(['outcome not recorded, 2 findings', 0], [$count->text(), $count->filter('.figure-kind')->count()]);
    }

    public function testTheReviewersTableMarksItsFindingsColumnCountedOnceInItsHead(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $head = $crawler->filter('#round-round-1 table.reviewers thead th.num');
        self::assertSame(['Findings counted', ['counted']], [$this->wordsOf($head), $head->filter('.figure-kind')->each(static fn (Crawler $label): ?string => $label->attr('data-kind'))]);
        self::assertSame(['2', 0], [$crawler->filter('#round-round-1 table.reviewers tbody td.num')->text(), $crawler->filter('#round-round-1 table.reviewers tbody .figure-kind')->count()]);
    }

    public function testAReviewerWithoutAnAnswerSaysSoWithNoCountAndNoLabel(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $count = $crawler->filter('#round-round-1 table.reviewers tbody td.num');
        self::assertSame(['no answer yet', 0], [$count->text(), $count->filter('.figure-kind')->count()]);
    }

    public function testEachLabelNamesItsSourceInATooltipTiedToIt(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $labels = $crawler->filter('.figure-kind');
        self::assertSame(
            [FigureKind::Estimated->source(), FigureKind::Counted->source(), FigureKind::Estimated->source()],
            $labels->each(static fn (Crawler $label): string => $label->filter('.figure-kind-tip[role="tooltip"]')->text()),
        );
        self::assertSame($labels->each(static fn (Crawler $label): ?string => $label->filter('.figure-kind-tip')->attr('id')), $labels->each(static fn (Crawler $label): ?string => $label->attr('aria-describedby')));
    }

    public function testNoLabelOnThePageIsATabStop(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([null, null, null], $crawler->filter('.figure-kind')->each(static fn (Crawler $label): ?string => $label->attr('tabindex')));
    }

    public function testEachTooltipIsAnchoredToItsOwnLabelSoAScrollingTableCannotClipIt(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $labels = $crawler->filter('#round-round-1 .table-scroll .figure-kind');
        self::assertCount(2, $labels);
        $anchors = $labels->each(static fn (Crawler $label): ?string => $label->attr('style'));
        self::assertSame(
            array_map(static fn (?string $anchor): string => str_replace('anchor-name', 'position-anchor', (string) $anchor), $anchors),
            $labels->each(static fn (Crawler $label): ?string => $label->filter('.figure-kind-tip')->attr('style')),
        );
        self::assertSame($labels->each(static fn (Crawler $label): string => 'anchor-name: --'.$label->attr('aria-describedby')), $anchors);
    }

    private function givenPricesOf(?string $day): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], $day));
    }

    private function givenAJudgedRound(string $round = 'round-1', int $number = 1): void
    {
        $this->ledger->startRoundOf('feat/thing', $round, $number);
        $this->ledger->startReviewer($round, 'correctness');
        $this->ledger->useTokens($round, 1000, 300);
        $this->ledger->answer($round, 'correctness', 2);
        $this->ledger->judgeFindings($round, ['F1' => 'act', 'F2' => 'defer']);
    }

    // Running, because the page shows the session summary only for a round still in progress.
    private function givenARunningRoundWhoseSessionCosts(Cost $cost): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->useTokens('round-1', 1000, 300);
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn(new SessionSummary([], $cost, new Paths(), [], [], [], false));
        self::getContainer()->set(SessionSummaries::class, $summaries);
    }

    // The tooltip's words are inside the label, so they are left out to read the figures as a person sees them.
    private function wordsOf(Crawler $node, string $before = ''): string
    {
        $text = $node->text();

        foreach ($node->filter('.figure-kind-tip') as $tip) {
            $text = str_replace($tip->textContent, '', $text);
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return '' === $before ? $text : explode($before, $text)[0];
    }
}
