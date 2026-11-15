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
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Tests\Support\FixedRoundEndings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;
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
#[UsesClass(StoredRoundEndings::class)]
#[UsesClass(RoundEnding::class)]
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
            [['correctness', 'standard', 'verdict', 'changes', '2 counted', '', ''], ['security', 'standard', 'failed', '', '', 'The provider is down.', '']],
            $this->rowsOf($crawler->filter('#round-round-1 table.reviewers tbody tr')),
        );
        self::assertSame(
            [['correctness', 'standard', 'verdict', 'approve', '0 counted', '', ''], ['security', 'standard', 'verdict', 'approve', '0 counted', 'answered by the substitute openai, a catch-up review is owed', '']],
            $this->rowsOf($crawler->filter('#round-round-2 table.reviewers tbody tr')),
        );
    }

    public function testTheReviewPageShowsAVerdictRecordedInTheRemovedShadowModeLikeAnOrdinaryOne(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answerInShadow('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->answer('round-1', 'security', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $rows = $this->rowsOf($crawler->filter('table.reviewers tbody tr'));
        self::assertSame(['approve', 'approve'], [$rows[0][3], $rows[1][3]]);
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
        self::assertSelectorTextContains('#round-round-1 .chip.state-running', 'In progress');
    }

    public function testARoundStillWithinItsWaitStaysInProgressAndKeepsItsLiveTopic(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filter('#round-round-1-now-phase'));
        self::assertCount(0, $crawler->filter('.round-ending'));
    }

    public function testARoundThatNeverReportedShowsAsBlockedWithNoOutcomeInTime(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSelectorTextContains('#round-round-1 .chip.state-blocked', 'Blocked');
        self::assertStringContainsString('Blocked: '.RoundEndReasons::NO_OUTCOME_IN_TIME.': the round did not end within 10800 seconds', $crawler->filter('#round-round-1-ending')->text());
    }

    public function testARoundThatEndedWithoutAnOutcomeIsNoLongerTheActiveRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('#round-round-1-now-phase'));
        self::assertCount(0, $crawler->filter('#live-log-pane'));
    }

    public function testAReviewerStillMarkedRunningInARoundThatEndedHasNoStopForm(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringContainsString('running', $crawler->filter('#round-round-1-reviewers')->text());
        self::assertCount(0, $crawler->filter('form.stop-reviewer'));
    }

    public function testARoundWhoseJudgingFailedHasNoLiveLog(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('#live-log-pane'));
    }

    public function testARoundWhoseJudgingFailedShowsAsBlockedWithTheReasonTheJudgingFailed(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSelectorTextContains('#round-round-1 .chip.state-blocked', 'Blocked');
        self::assertSame('Blocked: the judging failed', $crawler->filter('#round-round-1-ending')->text());
    }

    public function testAJudgedRoundPastTheUpperWaitStaysJudged(): void
    {
        $this->givenACompletedTwoRoundReview();
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(2, $crawler->filter('section.round .chip.state-judged'));
        self::assertCount(0, $crawler->filter('.chip.state-blocked'));
    }

    public function testAJudgedRoundCarriesTheJudgedChip(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame(['Judged', 'Judged'], $crawler->filter('section.round .chip.state-judged')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function testThePageHeadHoldsTheTitleAndTheSummaryAsItsLead(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame('Review of feat/thing in alpha', $crawler->filter('.page-head h1')->text());
        self::assertCount(1, $crawler->filter('.page-head p.summary.lead'));
    }

    public function testEachRoundNamesItsIdAndStartOnItsMetaLine(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['round-1', 'Started 2026-10-28 12:00:00 UTC'], $crawler->filter('#round-round-1 .meta > span')->each(static fn (Crawler $fact): string => $fact->text()));
    }

    public function testEachRoundShowsItsTokensAndTheReviewTheirSumWithUnknownCostWhenTheModelHasNoPrice(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['1500 measured', '0 measured', '0 measured', '0 measured', '500 measured', '2000 measured', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr')));
        self::assertSame([['700 measured', '0 measured', '0 measured', '0 measured', '100 measured', '800 measured', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr')));
        self::assertSame('2 rounds counted, 2800 tokens measured in all. Cost: unknown, no price for model-a.', self::wordsOf($crawler->filter('p.summary')));
    }

    public function testEachRoundShowsItsCostAtTheConfiguredPriceAndTheReviewTheSumOfThem(): void
    {
        $this->priceModelA();
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0080 USD estimated, price date unknown', $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr'))[0][6]);
        self::assertSame('0.0024 USD estimated, price date unknown', $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr'))[0][6]);
        self::assertStringEndsWith('Cost: 0.0104 USD estimated, price date unknown.', self::wordsOf($crawler->filter('p.summary')));
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

        self::assertSame('0.0050 USD estimated, price date unknown', $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr'))[0][6]);
        self::assertSelectorTextContains('p.summary', 'Cost: unknown, no price for model-b.');
    }

    public function testAnAnthropicRoundShowsItsCacheReadsAndWritesWithinThePromptAndTheirSumAsTotal(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useRecordedTokens('round-1', ['account' => 'anthropic.default', 'prompt' => 4, 'completion' => 700, 'cached' => 39500, 'cache_creation' => 1500, 'cache_read' => 38000, 'total' => null]);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['39504 measured', '38000 measured', '1500 measured', '0 measured', '700 measured', '40204 measured', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
    }

    public function testTokensOfACallStartedForAnotherRoundAreNotCounted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useTokens('round-elsewhere', 100, 10);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['0 measured', '0 measured', '0 measured', '0 measured', '0 measured', '0 measured', '0.0000 USD estimated, price date unknown']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
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
        self::assertLoggedClientError(sprintf('Uncaught PHP Exception %s: "The ledger holds no review with this round."', NotFoundHttpException::class));
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

    public function testTheHeaderShowsTheMetaLineWithProjectChangeAndLatestStart(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame(['alpha', 'feat/thing', 'Latest round started 2026-10-28 12:00:00 UTC'], $crawler->filter('.page-head p.meta span')->each(static fn (Crawler $fact): string => $fact->text()));
    }

    public function testACompletedRoundFoldsToOneLineWithOutcomeFindingCountAndTimeSpan(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame(['Round 1', 'Judged', 'outcome not recorded, 3 findings counted', '12:00 to 12:00 UTC'], $this->partsOf($crawler->filter('#round-round-1 details.ev > summary')));
        self::assertSame(['Round 2', 'Judged', 'outcome not recorded, 0 findings counted', '12:00 to 12:00 UTC'], $this->partsOf($crawler->filter('#round-round-2 details.ev > summary')));
        self::assertSelectorNotExists('#round-round-1 details[open]');
    }

    public function testAFoldedRoundJudgedBeforeTheFindingsWereKeptSaysTheyWereNotRecordedInsteadOfZero(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 3);
        $this->ledger->judgeMarksOnly('round-1', [], []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        $summary = $crawler->filter('#round-round-1 details.ev > summary')->text();
        self::assertStringContainsString('outcome not recorded, findings not recorded', $summary);
        self::assertStringNotContainsString('0 findings', $summary);
        self::assertStringContainsString('findings were not recorded', $crawler->filter('#round-round-1 details.ev')->text());
    }

    public function testAFoldedRoundJudgedBeforeTheFindingsWereKeptShowsTheJudgesMarksAsMarksNotAsTheFindingCount(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 5);
        $this->ledger->judgeMarksOnly('round-1', ['F1'], ['F2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $summary = $crawler->filter('#round-round-1 details.ev > summary')->text();
        self::assertStringContainsString('findings not recorded, 2 marked by the judge', $summary);
        self::assertStringNotContainsString('2 findings', $summary);
    }

    public function testTheActiveRoundStaysOpenInTheTimelineAndCompletedOnesFold(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(1, $crawler->filter('details.ev'));
        self::assertCount(0, $crawler->filter('#round-round-2 details'));
        self::assertCount(1, $crawler->filter('#round-round-2-state'));
    }

    public function testTheCurrentStateBlockListsEachReviewersStatusOfTheActiveRound(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSelectorTextContains('.state-block h2', 'Now: round 2 in review');
        self::assertSame(['correctness (standard): verdict, approve', 'security (standard): running'], $crawler->filter('.state-block .sb-list li')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSelectorTextContains('.state-block', 'The judge has not judged this round yet.');
    }

    public function testAnOpenFindingLinksToItsRowInTheTimeline(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        $links = $crawler->filter('.state-block a.sb-finding');
        self::assertCount(1, $links);
        self::assertSame('#round-round-1-finding-F1', $links->attr('href'));
        self::assertCount(1, $crawler->filter('td#round-round-1-finding-F1'));
    }

    public function testAnOpenActEntryThatMergesFindingsNamesTheMergedIdsAndLinksToItsRow(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F2');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        $links = $crawler->filter('.state-block a.sb-finding');
        self::assertSame(['P1F1+F2 Text of F1.'], $links->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('#round-round-1-finding-F1', $links->attr('href'));
        self::assertSame(['F1+F2'], $crawler->filter('#round-round-1 table.findings td.mono')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testAReviewWithEveryRoundJudgedShowsNoCurrentStateBlock(): void
    {
        $this->givenACompletedTwoRoundReview();

        $this->browser->request('GET', '/reviews/round-2');

        self::assertSelectorNotExists('.state-block');
    }

    public function testTheLiveLogOfTheRunningRoundSitsBesideTheTimeline(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(2, $crawler->filter('.task-grid > .tl-pane .round'));
        self::assertSame('Live log of round 2', $crawler->filter('.task-grid > aside.log-pane h2')->text());
        self::assertSame(['correctness (standard) live', 'security (standard) live'], $crawler->filter('.log-pane #round-round-2-live .live h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertCount(0, $crawler->filter('.tl-pane .live'));
    }

    public function testAReviewWithEveryRoundJudgedShowsNoLiveLog(): void
    {
        $this->givenACompletedTwoRoundReview();

        $this->browser->request('GET', '/reviews/round-2');

        self::assertSelectorNotExists('.log-pane');
        self::assertSelectorExists('.task-grid > .tl-pane #round-round-2');
    }

    public function testTheLiveLogOfARoundNoReviewerStartedSaysWhatWillAppear(): void
    {
        $this->givenACompletedTwoRoundReview();
        $this->ledger->startRoundOf('feat/thing', 'round-3', 3);

        $crawler = $this->browser->request('GET', '/reviews/round-3');

        self::assertSame('Each reviewer\'s tool calls and text appear here as it works.', $crawler->filter('.log-pane .log-empty')->text());
        self::assertCount(0, $crawler->filter('#round-round-3-live .live'));
    }

    public function testOnlyTheLatestOfTwoUnjudgedRoundsGetsTheLiveLog(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(1, $crawler->filter('.log-pane'));
        self::assertCount(1, $crawler->filter('#round-round-2-live'));
        self::assertCount(0, $crawler->filter('#round-round-1-live'));
        self::assertSame('Live log of round 2', $crawler->filter('.log-pane h2')->text());
    }

    public function testALongChangeNameIsShownWhole(): void
    {
        $change = 'feat/'.str_repeat('a-very-long-branch-name-', 8);
        $this->ledger->startRoundOf($change, 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame($change, $crawler->filter('.page-head p.meta span.mono')->text());
    }

    /**
     * @return list<string>
     */
    private function partsOf(Crawler $summary): array
    {
        return $summary->children()->each(static fn (Crawler $part): string => self::wordsOf($part));
    }

    private function givenARoundJudgedAndASecondRunning(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');
        $this->ledger->startReviewer('round-2', 'security');
        $this->ledger->answer('round-2', 'correctness', 0);
    }

    private function priceModelA(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]]));
    }

    public function testEveryTableOfTheReviewPageScrollsInANamedRegionTheKeyboardReaches(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $wrappers = $crawler->filter('.table-scroll');
        self::assertGreaterThan(0, $wrappers->count());
        self::assertCount($wrappers->count(), $crawler->filter('.table-scroll[tabindex="0"][role="region"][aria-label]:not([aria-label=""])'));
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
        return $rows->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => self::wordsOf($cell)));
    }

    // A kind label's tooltip words are inside the label, so they are left out to read the figures as a person sees them.
    private static function wordsOf(Crawler $node): string
    {
        $text = $node->text();

        foreach ($node->filter('.figure-kind-tip') as $tip) {
            $text = str_replace($tip->textContent, '', $text);
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
