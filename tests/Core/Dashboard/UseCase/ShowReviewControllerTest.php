<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\AiLab;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\ReplayedStart;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\ReplayMark;
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
use Psr\Log\LogLevel;
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
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ReplayedStart::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(AiLab::class)]
final class ShowReviewControllerTest extends DashboardPageTestCase
{
    public function testTheReviewPageShowsEveryRoundOfTheChangeInOrder(): void
    {
        $this->givenACompletedTwoRoundReview();
        $this->ledger->startRoundOf('feat/other', 'round-other', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Full record of feat/thing in alpha');
        self::assertSame(['Review pass 1', 'Review pass 2'], $crawler->filter('section.round h2')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testTheReviewPageOfAProjectFlaggedAsReplayShowsTheReplayMark(): void
    {
        $this->flagAsReplay('alpha');
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame('Replay A recording played back: its verdicts were never given on this change and never count as one.', $crawler->filter('.page-head .replay-mark')->text());
    }

    public function testTheReviewPageOfALiveProjectShowsNoReplayMark(): void
    {
        $this->flagAsReplay('beta');
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(0, $crawler->filter('.replay-mark'));
    }

    public function testTheReviewPageOfAProjectFlaggedAsReplayMarksEachRoundHeading(): void
    {
        $this->flagAsReplay('alpha');
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['Replay', 'Replay'], $crawler->filter('section.round .replay-mark')->each(static fn (Crawler $mark): string => $mark->text()));
    }

    public function testAMixedReviewMarksOnlyItsReplayRoundAndSaysNothingOfTheVerdictsAtTheTop(): void
    {
        $this->storeRoundAs('round-1', ReplayMark::Live);
        $this->storeRoundAs('round-2', ReplayMark::Replay);
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $this->browser->disableReboot();
        $crawler = $this->browser->request('GET', '/reviews/round-1/record');
        $main = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [0, 1, 1, 0, 0],
            [$crawler->filter('#round-round-1 .replay-mark')->count(), $crawler->filter('#round-round-2 .replay-mark')->count(), $main->filter('.state-block .sb-head .replay-mark')->count(), $main->filter('.page-head .replay-mark')->count(), $crawler->filter('.page-head .replay-mark')->count()],
        );
    }

    public function testTheReviewPageOfAReplayedRoundShowsItsSpeedFactorBesideTheReplayMark(): void
    {
        $this->flagAsReplay('alpha');
        $this->ledger->startReplayedRoundOf('feat/thing', 'round-1', 4.0);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Replay', 'Played 4 times as fast as recorded'], [$crawler->filter('#round-round-1 .replay-mark')->text(), $crawler->filter('#round-round-1 .replay-factor')->text()]);
    }

    public function testAJudgedReplayedRoundShowsAFractionalSpeedFactorInItsHeading(): void
    {
        $this->flagAsReplay('alpha');
        $this->ledger->startReplayedRoundOf('feat/thing', 'round-1', 1.5);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Played 1.5 times as fast as recorded', $crawler->filter('#round-round-1 summary .replay-factor')->text());
    }

    public function testTheReviewPageOfALiveRoundShowsNoSpeedFactor(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(0, $crawler->filter('.replay-factor'));
    }

    public function testAStoredFactorNoReplayCouldRunAtShowsNoSpeedFactorButKeepsTheRound(): void
    {
        $this->flagAsReplay('alpha');
        $this->ledger->startReplayedRoundOf('feat/thing', 'round-1', 0.0);
        $this->ledger->startReplayedRoundOf('feat/thing', 'round-2', 'fast');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([2, 0], [$crawler->filter('section.round')->count(), $crawler->filter('.replay-factor')->count()]);
    }

    public function testEachRoundListsItsReviewersWithTheirVerdicts(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(
            [['correctness', 'standard tier, model not recorded', 'Changes needed', '2'], ['security', 'standard tier, model not recorded', 'Failed The provider is down.', 'no answer']],
            $this->rowsOf($crawler->filter('#round-round-1 table.reviewers tbody tr')),
        );
        self::assertSame(
            [['correctness', 'standard tier, model not recorded', 'Approved', '0'], ['security', 'standard tier, model not recorded', 'Approved ; answered by the substitute openai, a catch-up review is owed', '0']],
            $this->rowsOf($crawler->filter('#round-round-2 table.reviewers tbody tr')),
        );
    }

    public function testEachReviewerIsNamedByRoleAndModelWithItsOutcomeAndFindingCount(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'kimi-k3');
        $this->ledger->startReviewerOn('round-1', 'security', 'model-b');
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', []);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([['correctness', 'Kimi K3 (Moonshot AI)', 'Changes needed', '2'], ['security', 'model-b', 'Approved', '0']], $this->rowsOf($crawler->filter('table.reviewers tbody tr')));
    }

    public function testAReviewersModelCellKeepsTheRawModelIdInItsTitle(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'moonshotai/Kimi-K3.1:deepinfra');

        $cell = $this->browser->request('GET', '/reviews/round-1/record')->filter('#round-round-1-reviewer-round-1-correctness-model');

        self::assertSame(['Kimi K3.1 (Moonshot AI)', 'moonshotai/Kimi-K3.1:deepinfra'], [$cell->text(), $cell->attr('title')]);
    }

    public function testAReviewerOnAModelIdThatTwigReadsAsFalseStillHasItsIdInTheTitle(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', '0');

        $cell = $this->browser->request('GET', '/reviews/round-1/record')->filter('#round-round-1-reviewer-round-1-correctness-model');

        self::assertSame(['0', '0'], [$cell->text(), $cell->attr('title')]);
    }

    public function testAReviewerWhoseModelWasNotRecordedHasNoTitleOnItsModelCell(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $cell = $this->browser->request('GET', '/reviews/round-1/record')->filter('#round-round-1-reviewer-round-1-correctness-model');

        self::assertSame(['standard tier, model not recorded', null], [$cell->text(), $cell->attr('title')]);
    }

    public function testAJudgedRoundsReviewersTableHasNoEmptyColumn(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Role', 'Model', 'Outcome', 'Findings'], $crawler->filter('#round-round-1 table.reviewers thead th')->each(static fn (Crawler $header): string => self::wordsOf($header)));
        self::assertNotContains('', array_merge(...$this->rowsOf($crawler->filter('table.reviewers tbody tr'))));
    }

    public function testARoundWhoseReviewersAllFinishedBeforeTheJudgeHasNoStopColumn(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'kimi-k3');
        $this->ledger->startReviewerOn('round-1', 'security', 'model-b');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Role', 'Model', 'Outcome', 'Findings counted'], $crawler->filter('table.reviewers thead th')->each(static fn (Crawler $header): string => self::wordsOf($header)));
        self::assertSame([4, 4], $crawler->filter('table.reviewers tbody tr')->each(static fn (Crawler $row): int => $row->filter('td')->count()));
    }

    public function testAReviewerStartedBeforeTheModelWasRecordedShowsItsTierInsteadOfAnEmptyModel(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('standard tier, model not recorded', $crawler->filter('table.reviewers tbody td')->eq(1)->text());
    }

    public function testARunningReviewerShowsAStateDotAndAnApprovingOneTheApprovedChipWithItsCheck(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'kimi-k3');
        $this->ledger->startReviewerOn('round-1', 'security', 'model-b');
        $this->ledger->answer('round-1', 'security', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(
            [[1, 0, 'running'], [0, 1, 'Approved']],
            $crawler->filter('table.reviewers tbody td[id$="-state"]')->each(static fn (Crawler $cell): array => [$cell->filter('.dot')->count(), $cell->filter('svg.i')->count(), $cell->text()]),
        );
    }

    public function testAReviewerThatAnsweredChangesShowsTheChangesRequestedChipInItsRow(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Changes needed', $crawler->filter('#round-round-1-reviewer-round-1-correctness-state .chip')->text());
        self::assertSame('Changes requested', $crawler->filter('#round-round-1-reviewer-round-1-correctness-state .chip')->attr('title'));
        self::assertCount(0, $crawler->filter('#round-round-1-reviewer-round-1-correctness-state .chip.approved'));
    }

    public function testAFailedReviewerShowsTheFailedChipAndItsCauseAsMutedText(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Changes needed', 'Failed'], $crawler->filter('#round-round-1 table.reviewers .chip')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertSame(['The provider is down.'], $crawler->filter('#round-round-1 table.reviewers .reviewer-outcome .muted')->each(static fn (Crawler $cause): string => $cause->text()));
    }

    public function testAReviewerThatGaveNoApprovalWithoutNamingAFindingIsLabelledFailedAndNeverBlocked(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'model-a');
        $this->ledger->failReviewer('round-1', 'correctness', ChangesWithoutFindings::class);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $state = $crawler->filter('#round-round-1-reviewer-round-1-correctness-state');
        self::assertSame(['Failed', 'gave no approval without naming a finding'], [$state->filter('.chip.broken')->text(), $state->filter('.muted')->text()]);
        self::assertStringNotContainsString('Blocked', $crawler->filter('#round-round-1')->text());
    }

    public function testARunningReviewerHasNoFindingCountYetButSaysSo(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'kimi-k3');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Role', 'Model', 'Outcome', 'Findings counted', 'Stop'], $crawler->filter('table.reviewers thead th')->each(static fn (Crawler $header): string => self::wordsOf($header)));
        self::assertSame([['correctness', 'Kimi K3 (Moonshot AI)', 'running', 'no answer yet', 'Stop this reviewer']], $this->rowsOf($crawler->filter('table.reviewers tbody tr')));
    }

    public function testTheReviewPageShowsAVerdictRecordedInTheRemovedShadowModeLikeAnOrdinaryOne(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answerInShadow('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->answer('round-1', 'security', 0);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $rows = $this->rowsOf($crawler->filter('table.reviewers tbody tr'));
        self::assertSame(['Approved', 'Approved'], [$rows[0][2], $rows[1][2]]);
    }

    public function testAFailedReviewerAnsweredByASubstituteShowsBothNotesSeparated(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'security');
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->owesCatchUp('round-1', 'security');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $rows = $this->rowsOf($crawler->filter('table.reviewers tbody tr'));
        self::assertSame('Failed The provider is down.; answered by the substitute openai, a catch-up review is owed', $rows[0][2]);
    }

    public function testAReviewerStartedAgainAfterAFailureShowsAsRunning(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->failReviewer('round-1', 'correctness', 'The worker stopped.');
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([['correctness', 'standard tier, model not recorded', 'running', 'no answer yet', 'Stop this reviewer']], $this->rowsOf($crawler->filter('table.reviewers tbody tr')));
        self::assertSelectorTextContains('section.round', '1 of 2 reviewers started.');
    }

    public function testTheFindingsOfAJudgedRoundShowTheirIdsAndTheJudgesCall(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(
            [['P2', 'F1 Text of F1.', 'File and line not recorded', JudgeCall::Act->inWords()], ['P2', 'F2 Text of F2.', 'File and line not recorded', JudgeCall::FollowUp->inWords()], ['P2', 'F3 Text of F3.', 'File and line not recorded', JudgeCall::NotActedOn->inWords()]],
            $this->cardsOf($crawler->filter('#round-round-1 .finding-card')),
        );
    }

    public function testAJudgedRoundWithoutFindingsSaysTheJudgeHadNothingToMark(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertStringContainsString('the judge had nothing to mark', $crawler->filter('#round-round-2')->text());
    }

    public function testARoundJudgedBeforeTheFindingTextWasRecordedStillShowsTheMarkedIdsAndTheirCall(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeMarksOnly('round-1', ['F1'], ['F2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(
            [['Severity not recorded', 'F1 Text not recorded.', 'File and line not recorded', JudgeCall::Act->inWords()], ['Severity not recorded', 'F2 Text not recorded.', 'File and line not recorded', JudgeCall::FollowUp->inWords()]],
            $this->cardsOf($crawler->filter('#round-round-1 .finding-card')),
        );
        self::assertStringNotContainsString('reported no findings', $crawler->filter('#round-round-1')->text());
    }

    public function testARoundJudgedBeforeTheFindingTextWasRecordedWithoutMarksDoesNotClaimThereWereNoFindings(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeMarksOnly('round-1', [], []);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertStringNotContainsString('reported no findings', $crawler->filter('#round-round-1')->text());
        self::assertStringContainsString('findings were not recorded', $crawler->filter('#round-round-1')->text());
    }

    public function testARoundTheJudgeHasNotJudgedSaysSo(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertStringContainsString('The judge has not judged this round yet.', $crawler->filter('#round-round-1')->text());
        self::assertSelectorTextContains('#round-round-1-head-state .muted.state-running', 'In progress');
        self::assertCount(0, $crawler->filter('#round-round-1-head-state .chip'));
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

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSelectorTextContains('#round-round-1-head-state .chip', 'Blocked');
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

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

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

    public function testARoundWhoseJudgingFailedShowsAsFailedWithTheReasonTheJudgingFailed(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSelectorTextContains('#round-round-1-head-state .chip', 'Failed');
        self::assertSame('Failed: the judging failed', $crawler->filter('#round-round-1-ending')->text());
    }

    public function testAJudgedRoundPastTheUpperWaitStaysJudged(): void
    {
        $this->givenACompletedTwoRoundReview();
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(2, $crawler->filter('section.round .state-judged'));
        self::assertCount(0, $crawler->filter('.chip.state-blocked'));
    }

    public function testAJudgedRoundNamesItsStateAsMutedTextRatherThanAChip(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['Checked', 'Checked'], $crawler->filter('section.round summary .muted.state-judged')->each(static fn (Crawler $state): string => $state->text()));
        self::assertSame(['Judged', 'Judged'], $crawler->filter('section.round summary .muted.state-judged')->each(static fn (Crawler $state): string => (string) $state->attr('title')));
        self::assertCount(0, $crawler->filter('section.round summary .chip.state-judged'));
    }

    public function testTheNewestJudgedRoundStartsOpenAndAnOlderOneStartsClosed(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame([false, true], $crawler->filter('section.round details.ev')->each(static fn (Crawler $round): bool => null !== $round->attr('open')));
    }

    public function testAJudgedReviewShowsItsFindingsWithoutAClick(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertCount(2, $crawler->filter('#round-round-1 details.ev[open] .finding-card'));
    }

    public function testAJudgedRoundStartsClosedWhileANewerRoundIsStillInReview(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', []);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(1, $crawler->filter('#round-round-1 details.ev'));
        self::assertCount(0, $crawler->filter('details.ev[open]'));
    }

    public function testEveryJudgedRoundRowCarriesTheChevronInItsSummary(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame([1, 1], $crawler->filter('section.round details.ev > summary')->each(static fn (Crawler $row): int => $row->filter('svg.chev')->count()));
    }

    public function testARoundStillInReviewIsNoDisclosureAndCarriesNoChevron(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('#round-round-1 details, #round-round-1 .chev'));
    }

    public function testThePageHeadHoldsTheTitleTheTaskSentenceAndTheMetaLine(): void
    {
        $this->givenACompletedTwoRoundReview();

        $this->browser->disableReboot();
        $crawler = $this->browser->request('GET', '/reviews/round-2');
        $record = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame('Review of feat/thing in alpha', $crawler->filter('.page-head h1')->text());
        self::assertCount(1, $crawler->filter('.page-head p.task-sentence'));
        self::assertCount(0, $crawler->filter('.page-head p.meta'));
        self::assertSame('Full record of feat/thing in alpha', $record->filter('.page-head h1')->text());
        self::assertCount(1, $record->filter('.page-head p.meta'));
    }

    public function testThePageHeadShowsTheTaskSentenceOfTheLatestRoundUnderTheTitle(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Add the thing.', $crawler->filter('.page-head h1 + p.task-sentence')->text());
    }

    public function testThePageHeadSaysTheTaskWasNotRecordedWhenTheRoundHasNoSource(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Task not recorded.', $crawler->filter('.page-head p.task-sentence.muted')->text());
    }

    public function testTheMetaLineGivesRoundsTokensCostAndTimeEachItsIcon(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['rounds', 'tokens', 'wallet', 'clock'], $crawler->filter('.page-head p.meta > span svg.i')->each($this->iconName(...)));
    }

    public function testEachRoundNamesItsIdAndStartOnItsMetaLine(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['round-1', 'Started 2026-10-28 12:00:00 UTC'], $crawler->filter('#round-round-1 .meta > span')->each(static fn (Crawler $fact): string => $fact->text()));
    }

    public function testEachRoundShowsItsTokensAndTheReviewTheirSumWithUnknownCostWhenTheModelHasNoPrice(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([['1500', '0', '0', '0', '500', '2000', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr')));
        self::assertSame([['700', '0', '0', '0', '100', '800', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr')));
        self::assertSame('alphafeat/thing 2 rounds 2,800 tokens Cost: unknown, no price for model-a Latest round started 2026-10-28 12:00:00 UTC', self::wordsOf($crawler->filter('.page-head p.meta')));
    }

    public function testEachRoundShowsItsCostAtTheConfiguredPriceAndTheReviewTheSumOfThem(): void
    {
        $this->priceModelA();
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('0.0080 USD, price date unknown', $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr'))[0][6]);
        self::assertSame('0.0024 USD, price date unknown', $this->rowsOf($crawler->filter('#round-round-2 table.tokens tbody tr'))[0][6]);
        self::assertStringContainsString('Cost: 0.0104 USD estimated, price date unknown', self::wordsOf($crawler->filter('.page-head p.meta')));
    }

    public function testTheFullRecordViewRendersTheCostLabelAsOneWholeFactLabelBesideTheFigure(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Cost:'], $crawler->filter('.page-head p.meta .fact-label')->each(static fn (Crawler $label): string => $label->text(normalizeWhitespace: false)));
        self::assertCount(1, $crawler->filter('.page-head p.meta .fact-label + span#review-summary-cost'));
    }

    public function testAnUnknownCostNamesTheModelEscapedAndIsNeverZero(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useRecordedTokens('round-1', ['prompt' => 10, 'completion' => 1, 'total' => 11], '<b>odd</b>');

        $this->browser->request('GET', '/reviews/round-1/record');

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

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('0.0050 USD, price date unknown', $this->rowsOf($crawler->filter('#round-round-1 table.tokens tbody tr'))[0][6]);
        self::assertSelectorTextContains('.page-head p.meta', 'Cost: unknown, no price for model-b');
    }

    public function testAnAnthropicRoundShowsItsCacheReadsAndWritesWithinThePromptAndTheirSumAsTotal(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useRecordedTokens('round-1', ['account' => 'anthropic.default', 'prompt' => 4, 'completion' => 700, 'cached' => 39500, 'cache_creation' => 1500, 'cache_read' => 38000, 'total' => null]);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([['39504', '38000', '1500', '0', '700', '40204', 'unknown, no price for model-a']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
    }

    public function testTokensOfACallStartedForAnotherRoundAreNotCounted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useTokens('round-elsewhere', 100, 10);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame([['0', '0', '0', '0', '0', '0', '0.0000 USD, price date unknown']], $this->rowsOf($crawler->filter('table.tokens tbody tr')));
    }

    public function testARoundStartedWithoutAChangeNameIsAReviewOfItsOwn(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'alpha');

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['Review pass (number not recorded)'], $crawler->filter('section.round h2')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSelectorTextContains('h1', 'Full record of round round-2 in alpha');
    }

    public function testARoundOfTheSameChangeNameInAnotherProjectIsAnotherReview(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startRound('round-beta', 'beta');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertCount(1, $crawler->filter('section.round'));
    }

    public function testARoundTheLedgerNeverStartedIsNotFound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $this->browser->request('GET', '/reviews/round-unknown');

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The ledger holds no review with this round."', NotFoundHttpException::class));
    }

    public function testAFindingTextWithMarkupIsEscaped(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['<b>F1</b>' => 'act']);

        $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSelectorNotExists('main b');
        self::assertStringContainsString('Text of &lt;b&gt;F1&lt;/b&gt;.', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheReviewListLinksEachRoundToItsReviewPage(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews');

        self::assertSame('/reviews/round-1', $crawler->filter('.review-row a.review-link')->attr('href'));
    }

    public function testTheHeaderShowsTheMetaLineWithProjectChangeAndLatestStart(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['alpha', 'feat/thing', '2 rounds', '2,800 tokens', 'Latest round started 2026-10-28 12:00:00 UTC'], array_values(array_filter($crawler->filter('.page-head p.meta > span')->each(static fn (Crawler $fact): string => self::wordsOf($fact)), static fn (string $fact): bool => !str_starts_with($fact, 'Cost:'))));
    }

    public function testACompletedRoundFoldsToOneLineWithOutcomeFindingCountAndTimeSpan(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['Review pass 1', 'Checked', 'outcome not recorded, 3 findings', '12:00 to 12:00 UTC', ''], $this->partsOf($crawler->filter('#round-round-1 details.ev > summary')));
        self::assertSame(['Review pass 2', 'Checked', 'outcome not recorded, 0 findings', '12:00 to 12:00 UTC', ''], $this->partsOf($crawler->filter('#round-round-2 details.ev > summary')));
        self::assertSelectorNotExists('#round-round-1 details[open]');
    }

    public function testAFoldedRoundJudgedBeforeTheFindingsWereKeptSaysTheyWereNotRecordedInsteadOfZero(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 3);
        $this->ledger->judgeMarksOnly('round-1', [], []);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

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

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $summary = $crawler->filter('#round-round-1 details.ev > summary')->text();
        self::assertStringContainsString('findings not recorded, 2 marked by the judge', $summary);
        self::assertStringNotContainsString('2 findings', $summary);
    }

    public function testARoundJudgedChangesShowsTheChangesRequestedChipRightAfterItsHeading(): void
    {
        $this->givenARoundJudgedChanges();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['Review pass 1', 'Changes needed', 'Checked', '1 finding', '12:00 to 12:00 UTC', ''], $this->partsOf($crawler->filter('#round-round-1 details.ev > summary')));
    }

    public function testTheChangesRequestedChipCarriesItsOwnIcon(): void
    {
        $this->givenARoundJudgedChanges();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertStringContainsString('M20 4v5h-5', $crawler->filter('#round-round-1 summary > .chip')->html());
    }

    public function testARoundSummaryNeverShowsABareDecisionWordAsItsOutcome(): void
    {
        $this->givenARoundJudgedChanges();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertDoesNotMatchRegularExpression('/\b(changes|approve|act|verdict)\b/', $crawler->filter('#round-round-1 details.ev > summary')->text());
    }

    public function testAnApprovedRoundSummaryCarriesTheApprovedChip(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Approved', $crawler->filter('#round-round-1 summary > .chip.approved')->text());
    }

    public function testAJudgedRoundsOutcomeLineWordsTheQuorumsDecisionAsChangesRequested(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Outcome: Changes requested, decided by the quorum policy all over all 2 reviewers.', $crawler->filter('#round-round-1-outcome')->text());
    }

    public function testARoundWhoseReviewersAllFailedShowsTheFailedChipInItsSummary(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->failReviewer('round-1', 'correctness', 'The provider is down.');
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(ReviewOutcome::Failed->value, $crawler->filter('#round-round-1 summary > .chip.broken')->text());
    }

    public function testARoundWithoutARecordedOutcomeShowsNoOutcomeChip(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(0, $crawler->filter('section.round summary > .chip'));
    }

    public function testTheActiveRoundStaysOpenInTheTimelineAndCompletedOnesFold(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(1, $crawler->filter('details.ev'));
        self::assertCount(0, $crawler->filter('#round-round-2 details'));
        self::assertCount(1, $crawler->filter('#round-round-2-state'));
    }

    public function testTheCurrentStateBlockListsEachReviewersStatusOfTheActiveRound(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSelectorTextContains('.state-block h2 .term[data-term="pass"]', 'Review pass 2?');
        self::assertSelectorTextContains('.state-block h2', 'in review');
        self::assertSame(['correctness (standard tier, model not recorded): Approved', 'security (standard tier, model not recorded): running'], $crawler->filter('.state-block .sb-list li')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSelectorTextContains('.state-block', 'The judge has not judged this round yet.');
    }

    public function testTheCurrentStateBlockAndTheLiveLogNameEachReviewerByRoleAndModelLikeTheTable(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewerOn('round-1', 'correctness', 'Kimi K3');

        $this->browser->disableReboot();
        $main = $this->browser->request('GET', '/reviews/round-1');
        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame(['correctness (Kimi K3 (Moonshot AI)): running'], $main->filter('.state-block .sb-list li')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame([['correctness (Kimi K3 (Moonshot AI)) live', 'Kimi K3']], $crawler->filter('.log-pane .live h3')->each(static fn (Crawler $heading): array => [$heading->text(), $heading->attr('title')]));
    }

    public function testAnOpenFindingLinksToItsRowInTheTimeline(): void
    {
        $this->givenARoundJudgedAndASecondRunning();

        $this->browser->disableReboot();
        $links = $this->browser->request('GET', '/reviews/round-2')->filter('.state-block a.sb-finding');
        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(1, $links);
        self::assertSame('/reviews/round-1/record#round-round-1-finding-F1', $links->attr('href'));
        self::assertCount(1, $crawler->filter('li.finding-card#round-round-1-finding-F1'));
    }

    public function testAnOpenActEntryThatMergesFindingsShowsOneNumberNamesTheMergedIdsAsSecondaryTextAndLinksToItsRow(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F2');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $this->browser->disableReboot();
        $links = $this->browser->request('GET', '/reviews/round-2')->filter('.state-block a.sb-finding');
        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['P1F1 Text of F1.'], $links->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('/reviews/round-1/record#round-round-1-finding-F1', $links->attr('href'));
        self::assertSame(['F1'], $crawler->filter('#round-round-1 .finding-card .fc-id')->each(static fn (Crawler $id): string => $id->text()));
        self::assertSame(['Also reported as F2'], $crawler->filter('#round-round-1 .finding-card .fc-merged')->each(static fn (Crawler $merged): string => $merged->text()));
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

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(2, $crawler->filter('.task-grid > .tl-pane .round'));
        self::assertSame('Live log of review pass 2', $crawler->filter('.task-grid > section.log-pane h2')->text());
        self::assertSame(['correctness (standard tier, model not recorded) live', 'security (standard tier, model not recorded) live'], $crawler->filter('.log-pane #round-round-2-live .live h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertCount(0, $crawler->filter('.tl-pane .live'));
    }

    public function testAReviewWithEveryRoundJudgedShowsNoLiveLog(): void
    {
        $this->givenACompletedTwoRoundReview();

        $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSelectorNotExists('.log-pane');
        self::assertSelectorExists('.task-grid > .tl-pane #round-round-2');
    }

    public function testTheLiveLogOfARoundNoReviewerStartedSaysWhatWillAppear(): void
    {
        $this->givenACompletedTwoRoundReview();
        $this->ledger->startRoundOf('feat/thing', 'round-3', 3);

        $crawler = $this->browser->request('GET', '/reviews/round-3/record');

        self::assertSame('Each reviewer\'s tool calls and text appear here as it works.', $crawler->filter('.log-pane .log-empty')->text());
        self::assertCount(0, $crawler->filter('#round-round-3-live .live'));
    }

    public function testOnlyTheLatestOfTwoUnjudgedRoundsGetsTheLiveLog(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(1, $crawler->filter('.log-pane'));
        self::assertCount(1, $crawler->filter('#round-round-2-live'));
        self::assertCount(0, $crawler->filter('#round-round-1-live'));
        self::assertSame('Live log of review pass 2', $crawler->filter('.log-pane h2')->text());
    }

    public function testALongChangeNameIsShownWhole(): void
    {
        $change = 'feat/'.str_repeat('a-very-long-branch-name-', 8);
        $this->ledger->startRoundOf($change, 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame($change, $crawler->filter('.page-head p.meta span.mono')->text());
    }

    public function testEveryTableOfTheReviewPageScrollsInANamedRegionTheKeyboardReaches(): void
    {
        $this->givenACompletedTwoRoundReview();

        $crawler = $this->browser->request('GET', '/reviews/round-1/record');

        $wrappers = $crawler->filter('.table-scroll');
        self::assertGreaterThan(0, $wrappers->count());
        self::assertCount($wrappers->count(), $crawler->filter('.table-scroll[tabindex="0"][role="region"][aria-label]:not([aria-label=""])'));
    }

    /**
     * @return list<string>
     */
    private function partsOf(Crawler $summary): array
    {
        return $summary->children()->each(static fn (Crawler $part): string => self::wordsOf($part));
    }

    private function givenARoundJudgedChanges(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);
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

    /**
     * @return list<list<string>>
     */
    private function cardsOf(Crawler $cards): array
    {
        return $cards->each(static fn (Crawler $card): array => [$card->filter('.sev')->text(), $card->filter('.fc-title')->text(), $card->filter('.fc-place')->text(), $card->filter('.fc-call')->text()]);
    }

    // An icon carries no name, so it is told apart by a shape only it draws.
    private function iconName(Crawler $icon): string
    {
        $shapes = ['rounds' => 'path[d="M20 4v5h-5"]', 'tokens' => 'ellipse', 'wallet' => 'circle[r="1.2"]', 'clock' => 'path[d="M12 7.5V12l3 2"]'];

        return (string) array_find_key($shapes, static fn (string $shape): bool => 1 === $icon->filter($shape)->count());
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
