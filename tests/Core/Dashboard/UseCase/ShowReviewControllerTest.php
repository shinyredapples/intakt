<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ShowReviewController::class)]
#[CoversClass(LedgerReviewPages::class)]
#[CoversClass(LedgerRounds::class)]
#[CoversClass(LedgerTokens::class)]
#[CoversClass(Review::class)]
#[CoversClass(ReviewedRound::class)]
#[CoversClass(ReviewerOutcome::class)]
#[CoversClass(RoundFinding::class)]
#[CoversClass(TokenFigures::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(Cost::class)]
#[UsesClass(RoundLedgerReading::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(ProjectMode::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(ListReviewsController::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
final class ShowReviewControllerTest extends DashboardPageTestCase
{
    public function testTheReviewPageShowsEveryRoundOfTheChangeInOrder(): void
    {
        $this->givenACompletedTwoRoundReview();
        $this->ledger->startRoundOf('feat/other', 'round-other', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Review of feat/thing in alpha');
        self::assertSame(['Round 1', 'Round 2'], $crawler->filter('section.round h2')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testEachRoundListsItsReviewersWithTheirVerdicts(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [['correctness', 'standard', 'verdict', 'changes', '2', '', ''], ['security', 'standard', 'failed', '', '', 'The provider is down.', '']],
            $this->rowsOf($crawler->filter('#round-round-1 table.reviewers tbody tr')),
        );
        self::assertSame(
            [['correctness', 'standard', 'verdict', 'approve', '0', '', ''], ['security', 'standard', 'verdict', 'approve', '0', 'answered by the substitute openai, a catch-up review is owed', '']],
            $this->rowsOf($crawler->filter('#round-round-2 table.reviewers tbody tr')),
        );
    }

    public function testTheReviewPageMarksAShadowVerdictAndLeavesAnOrdinaryOneUnmarked(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answerInShadow('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->answer('round-1', 'security', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $rows = $this->rowsOf($crawler->filter('table.reviewers tbody tr'));
        self::assertSame(['approve (shadow)', 'approve'], [$rows[0][3], $rows[1][3]]);
    }

    public function testAFailedReviewerAnsweredByASubstituteShowsBothNotesSeparated(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->owesCatchUp('round-1', 'security');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $rows = $this->rowsOf($crawler->filter('table.reviewers tbody tr'));
        self::assertSame('The provider is down.; answered by the substitute openai, a catch-up review is owed', $rows[0][5]);
    }

    public function testAReviewerStartedAgainAfterAFailureShowsAsRunning(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->failReviewer('round-1', 'correctness', 'The worker stopped.');
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['correctness', 'standard', 'running', '', '', '', 'Stop this reviewer']], $this->rowsOf($crawler->filter('table.reviewers tbody tr')));
        self::assertSelectorTextContains('section.round', '1 of 2 reviewers started.');
    }

    public function testTheFindingsOfAJudgedRoundShowTheirIdsAndTheJudgesCall(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [['F1', 'P2', 'Text of F1.', JudgeCall::Act->value], ['F2', 'P2', 'Text of F2.', JudgeCall::FollowUp->value], ['F3', 'P2', 'Text of F3.', JudgeCall::NotActedOn->value]],
            $this->rowsOf($crawler->filter('#round-round-1 table.findings tbody tr')),
        );
    }

    public function testAJudgedRoundWithoutFindingsSaysTheJudgeHadNothingToMark(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertStringContainsString('the judge had nothing to mark', $crawler->filter('#round-round-2')->text());
    }

    public function testARoundJudgedBeforeTheFindingTextWasRecordedStillShowsTheMarkedIdsAndTheirCall(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeMarksOnly('round-1', ['F1'], ['F2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [['F1', 'not recorded', 'not recorded', JudgeCall::Act->value], ['F2', 'not recorded', 'not recorded', JudgeCall::FollowUp->value]],
            $this->rowsOf($crawler->filter('#round-round-1 table.findings tbody tr')),
        );
        self::assertStringNotContainsString('reported no findings', $crawler->filter('#round-round-1')->text());
    }

    public function testARoundJudgedBeforeTheFindingTextWasRecordedWithoutMarksDoesNotClaimThereWereNoFindings(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeMarksOnly('round-1', [], []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringNotContainsString('reported no findings', $crawler->filter('#round-round-1')->text());
        self::assertStringContainsString('findings were not recorded', $crawler->filter('#round-round-1')->text());
    }

    public function testARoundTheJudgeHasNotJudgedSaysSo(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringContainsString('The judge has not judged this round yet.', $crawler->filter('#round-round-1')->text());
        self::assertSelectorTextContains('#round-round-1 .state-running', 'in progress');
    }

    public function testEachRoundShowsItsTokensAndTheReviewTheirSumWithUnknownCostWhenTheModelHasNoPrice(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['1500', '0', '0', '0', '500', '2000', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr')));
        self::assertSame([['700', '0', '0', '0', '100', '800', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr')));
        self::assertSelectorTextContains('p.summary', '2 rounds, 2800 tokens in all. Cost: unknown, no price for model-a.');
    }

    public function testEachRoundShowsItsCostAtTheConfiguredPriceAndTheReviewTheSumOfThem(): void
    {
        $this->priceModelA();
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0080 USD', $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr'))[0][6]);
        self::assertSame('0.0024 USD', $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr'))[0][6]);
        self::assertSelectorTextContains('p.summary', 'Cost: 0.0104 USD.');
    }

    public function testAnUnknownCostNamesTheModelEscapedAndIsNeverZero(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useRecordedTokens('round-1', ['prompt' => 10, 'completion' => 1, 'total' => 11], '<b>odd</b>');

        $this->browser->request('GET', '/reviews/round-1');

        $page = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('unknown, no price for &lt;b&gt;odd&lt;/b&gt;', $page);
        self::assertStringNotContainsString('0.0000 USD', $page);
    }

    public function testOneUnpricedRoundMakesTheReviewSummaryUnknownWhileThePricedRoundKeepsItsFigure(): void
    {
        $this->priceModelA();
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useTokens('round-1', 1000, 300);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->useRecordedTokens('round-2', ['prompt' => 10, 'completion' => 1, 'total' => 11], 'model-b');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0050 USD', $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr'))[0][6]);
        self::assertSelectorTextContains('p.summary', 'Cost: unknown, no price for model-b.');
    }

    public function testAnAnthropicRoundShowsItsCacheReadsAndWritesWithinThePromptAndTheirSumAsTotal(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useRecordedTokens('round-1', ['account' => 'anthropic.default', 'prompt' => 4, 'completion' => 700, 'cached' => 39500, 'cache_creation' => 1500, 'cache_read' => 38000, 'total' => null]);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['39504', '38000', '1500', '0', '700', '40204', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
    }

    public function testTokensOfACallStartedForAnotherRoundAreNotCounted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useTokens('round-elsewhere', 100, 10);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['0', '0', '0', '0', '0', '0', '0.0000 USD']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
    }

    public function testARoundStartedWithoutAChangeNameIsAReviewOfItsOwn(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame(['Round (number not recorded)'], $crawler->filter('section.round h2')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSelectorTextContains('h1', 'Review of round round-2 in alpha');
    }

    public function testARoundOfTheSameChangeNameInAnotherProjectIsAnotherReview(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRound('round-beta', 'beta');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filter('section.round'));
    }

    public function testARoundTheLedgerNeverStartedIsNotFound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $this->browser->request('GET', '/reviews/round-unknown');

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError('error', sprintf('Uncaught PHP Exception %s: "The ledger holds no review with this round."', NotFoundHttpException::class));
    }

    public function testAFindingTextWithMarkupIsEscaped(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['<b>F1</b>' => 'act']);

        $this->browser->request('GET', '/reviews/round-1');

        self::assertSelectorNotExists('main b');
        self::assertStringContainsString('Text of &lt;b&gt;F1&lt;/b&gt;.', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheReviewListLinksEachRoundToItsReviewPage(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('/reviews/round-1', $crawler->filter('tbody td:first-child a')->attr('href'));
    }

    private function priceModelA(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]]));
    }

    private function givenACompletedTwoRoundReview(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->useTokens('round-1', 1000, 300);
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer', 'F3' => 'reject']);
        $this->ledger->useTokens('round-1', 500, 200);

        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');
        $this->ledger->startReviewer('round-2', 'security');
        $this->ledger->answer('round-2', 'correctness', 0);
        $this->ledger->answer('round-2', 'security', 0);
        $this->ledger->owesCatchUp('round-2', 'security');
        $this->ledger->useTokens('round-2', 700, 100);
        $this->ledger->judgeFindings('round-2', []);
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(Crawler $rows): array
    {
        return $rows->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
    }
}
