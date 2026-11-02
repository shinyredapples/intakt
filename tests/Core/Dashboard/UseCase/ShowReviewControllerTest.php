<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\TokenCount;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[CoversClass(LedgerReviewPages::class)]
#[CoversClass(LedgerRounds::class)]
#[CoversClass(LedgerTokens::class)]
#[CoversClass(Review::class)]
#[CoversClass(ReviewedRound::class)]
#[CoversClass(ReviewerOutcome::class)]
#[CoversClass(RoundFinding::class)]
#[CoversClass(TokenCount::class)]
#[UsesClass(LedgerPayload::class)]
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
            [['correctness', 'standard', 'verdict', 'changes', '2', ''], ['security', 'standard', 'failed', '', '', 'The provider is down.']],
            $this->rowsOf($crawler->filter('#round-round-1 table.reviewers tbody tr')),
        );
        self::assertSame(
            [['correctness', 'standard', 'verdict', 'approve', '0', ''], ['security', 'standard', 'verdict', 'approve', '0', 'answered by the substitute openai, a catch-up review is owed']],
            $this->rowsOf($crawler->filter('#round-round-2 table.reviewers tbody tr')),
        );
    }

    public function testAReviewerStartedAgainAfterAFailureShowsAsRunning(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->failReviewer('round-1', 'correctness', 'The worker stopped.');
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['correctness', 'standard', 'running', '', '', '']], $this->rowsOf($crawler->filter('table.reviewers tbody tr')));
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

    public function testEachRoundShowsItsTokensAndTheReviewTheirSumWithoutAPrice(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['1500', '500', '2000', 'no price']], $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr')));
        self::assertSame([['700', '100', '800', 'no price']], $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr')));
        self::assertSelectorTextContains('p.summary', '2 rounds, 2800 tokens in all. Cost: no price');
    }

    public function testTokensOfACallStartedForAnotherRoundAreNotCounted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useTokens('round-elsewhere', 100, 10);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['0', '0', '0', 'no price']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
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
