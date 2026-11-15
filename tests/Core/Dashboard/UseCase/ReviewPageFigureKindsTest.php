<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
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
    public function testTheSummaryMarksTheRoundCountCountedAndTheTokenTotalMeasured(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('1 round counted, 1300 tokens measured in all.', $this->wordsOf($crawler->filter('p.summary'), before: ' Cost:'));
    }

    public function testEachTokenCountOfARoundIsMarkedMeasured(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $tokens = $crawler->filter('#round-round-1 table.tokens tbody td.num');
        self::assertSame(
            ['1000 measured', '0 measured', '0 measured', '0 measured', '300 measured', '1300 measured'],
            $tokens->each(fn (Crawler $cell): string => $this->wordsOf($cell)),
        );
    }

    public function testTheReviewCostIsMarkedEstimatedWithTheDayOfItsPrices(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0050 USD estimated, prices of 2026-11-13.', explode('Cost: ', $this->wordsOf($crawler->filter('p.summary')))[1]);
    }

    public function testEachRoundsCostIsMarkedEstimatedWithTheDayOfItsPrices(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0050 USD estimated, prices of 2026-11-13', $this->wordsOf($crawler->filter('#round-round-1 table.tokens tbody td:last-child')));
    }

    public function testACostWithoutAPriceDateSaysTheDateIsUnknown(): void
    {
        $this->givenPricesOf(null);
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0050 USD estimated, price date unknown', $this->wordsOf($crawler->filter('#round-round-1 table.tokens tbody td:last-child')));
    }

    public function testAnUnknownCostCarriesNoLabelAndNoPriceDate(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('unknown, no price for model-a', $crawler->filter('#round-round-1 table.tokens tbody td:last-child')->text());
        self::assertStringEndsWith('Cost: unknown, no price for model-a.', $this->wordsOf($crawler->filter('p.summary')));
    }

    public function testTheSessionSummaryMarksThisRoundsAndTheReviewsCostEstimated(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenARunningRoundWhoseSessionCosts(Cost::inUsd(0.1, '2026-11-13'));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            ['0.1000 USD estimated, prices of 2026-11-13', '0.0050 USD estimated, prices of 2026-11-13'],
            $crawler->filter('.ls-cost dd')->each(fn (Crawler $cost): string => $this->wordsOf($cost)),
        );
    }

    public function testAStreamedRoundCostKeepsItsEstimatedLabelAndPriceDate(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $stream = $twig->render('dashboard/stream/summary_changed.stream.html.twig', ['round' => 'round-1', 'summary' => new SessionSummary([], Cost::inUsd(0.9, '2026-11-13'), [], [], [], [], false)]);

        $cost = new Crawler($stream)->filter('turbo-stream[target="round-round-1-summary-cost"]');
        self::assertSame('0.9000 USD estimated, prices of 2026-11-13', $this->wordsOf(new Crawler($cost->filter('template')->html())));
    }

    public function testRoundTokensAndCostStaySideBySideAndNeverAddUp(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $kinds = $crawler->filter('#round-round-1 table.tokens tbody td')->each(static fn (Crawler $cell): array => $cell->filter('.figure-kind')->each(static fn (Crawler $label): ?string => $label->attr('data-kind')));
        self::assertSame([['measured'], ['measured'], ['measured'], ['measured'], ['measured'], ['measured'], ['estimated']], $kinds);
    }

    public function testTheEstimatedLabelNamesThePriceListAndEveryTooltipOnThePageHasItsOwnId(): void
    {
        $this->givenPricesOf('2026-11-13');
        $this->givenARunningRoundWhoseSessionCosts(Cost::inUsd(0.1, '2026-11-13'));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $estimated = $crawler->filter('.figure-kind[data-kind="estimated"]');
        self::assertSame(array_fill(0, 4, FigureKind::Estimated->source()), $estimated->each(static fn (Crawler $label): string => $label->filter('.figure-kind-tip')->text()));
        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));
        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    public function testTheFindingCountOfAFoldedRoundIsMarkedCounted(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('outcome not recorded, 2 findings counted', $this->wordsOf($crawler->filter('#round-round-1 details.ev > summary .small')));
    }

    public function testEachReviewersFindingCountIsMarkedCounted(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('2 counted', $this->wordsOf($crawler->filter('#round-round-1 table.reviewers tbody td.num')));
    }

    public function testAReviewerWithoutAnAnswerShowsNoCountAndNoLabel(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('', $crawler->filter('#round-round-1 table.reviewers tbody td.num')->text());
    }

    public function testEachLabelNamesItsSourceInATooltipTiedToIt(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $labels = $crawler->filter('p.summary .figure-kind');
        self::assertSame([FigureKind::Counted->source(), FigureKind::Measured->source()], $labels->each(static fn (Crawler $label): string => $label->filter('.figure-kind-tip[role="tooltip"]')->text()));
        self::assertSame(['counted', 'measured'], $labels->each(static fn (Crawler $label): ?string => $label->attr('data-kind')));
        self::assertSame($labels->each(static fn (Crawler $label): ?string => $label->filter('.figure-kind-tip')->attr('id')), $labels->each(static fn (Crawler $label): ?string => $label->attr('aria-describedby')));
        self::assertSame(['0', '0'], $labels->each(static fn (Crawler $label): ?string => $label->attr('tabindex')));
    }

    public function testEachTooltipIsAnchoredToItsOwnLabelSoAScrollingTableCannotClipIt(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $labels = $crawler->filter('#round-round-1 table.tokens .figure-kind');
        self::assertCount(6, $labels);
        $anchors = $labels->each(static fn (Crawler $label): ?string => $label->attr('style'));
        self::assertSame(
            array_map(static fn (?string $anchor): string => str_replace('anchor-name', 'position-anchor', (string) $anchor), $anchors),
            $labels->each(static fn (Crawler $label): ?string => $label->filter('.figure-kind-tip')->attr('style')),
        );
        self::assertSame($labels->each(static fn (Crawler $label): string => 'anchor-name: --'.$label->attr('aria-describedby')), $anchors);
    }

    private function givenAJudgedRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->useTokens('round-1', 1000, 300);
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);
    }

    private function givenPricesOf(?string $day): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], $day));
    }

    // Running, because the page shows the session summary only for a round still in progress.
    private function givenARunningRoundWhoseSessionCosts(Cost $cost): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->useTokens('round-1', 1000, 300);
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn(new SessionSummary([], $cost, [], [], [], [], false));
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
