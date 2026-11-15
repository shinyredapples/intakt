<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Platform\Contract\FigureKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(FigureKind::class)]
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

    public function testTheCostOfARoundCarriesNoKindLabelHere(): void
    {
        $this->givenAJudgedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('#round-round-1 table.tokens tbody td:last-child .figure-kind'));
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

    private function givenAJudgedRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->useTokens('round-1', 1000, 300);
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);
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
