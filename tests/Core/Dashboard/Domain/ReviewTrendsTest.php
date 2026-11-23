<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\ChartScale;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerCatch;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewTrends::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(JudgeOutcomes::class)]
#[UsesClass(ReviewerCatches::class)]
#[UsesClass(ReviewerCatch::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ChartScale::class)]
final class ReviewTrendsTest extends TestCase
{
    public function testWithNoRoundEveryFigureButTheCountHasNoData(): void
    {
        $trends = ReviewTrends::counting([]);

        self::assertFalse($trends->anyReview);
        self::assertSame(0, $trends->roundsRun());
        self::assertNull($trends->seriousActFindings());
        self::assertNull($trends->caughtByOneReviewerOnlyPercent());
        self::assertNull($trends->medianTimeToVerdict());
        self::assertNull($trends->costPerRound());
    }

    public function testTheRoundsRunCountEveryRoundOfEveryProject(): void
    {
        $trends = ReviewTrends::counting([$this->round('r-1', 'alpha'), $this->round('r-2', 'beta'), $this->round('r-3', 'beta')]);

        self::assertSame(3, $trends->roundsRun());
    }

    public function testOnlyP1AndP2FindingsOnActListsAreCounted(): void
    {
        $judged = $this->judgedWith([$this->act('F1', 'P1'), $this->act('F2', 'P2'), $this->act('F3', 'P3'), new RoundFinding('F4', 'P1', 'Deferred.')->calledBy(JudgeCall::FollowUp)]);

        self::assertSame(2, ReviewTrends::counting([$judged])->seriousActFindings());
    }

    public function testJudgedRoundsWithoutActFindingsCountZeroSeriousFindings(): void
    {
        self::assertSame(0, ReviewTrends::counting([$this->judgedWith([])])->seriousActFindings());
    }

    public function testUnjudgedRoundsGiveNoDataOnSeriousFindings(): void
    {
        self::assertNull(ReviewTrends::counting([$this->round('r-1', 'alpha')])->seriousActFindings());
    }

    public function testAnActEntryKeptOnlyAsTheJudgesMarkGivesNoDataOnSeriousFindings(): void
    {
        $judged = $this->judgedWith([new RoundFinding('F1', null, null)->calledBy(JudgeCall::Act)]);

        self::assertNull(ReviewTrends::counting([$judged])->seriousActFindings());
    }

    public function testARoundJudgedFromMarksOnlyGivesNoDataOnSeriousFindings(): void
    {
        $judged = $this->round('r-1', 'alpha')->judgedFromMarksOnly([new RoundFinding('F1', null, null)->calledBy(JudgeCall::Act)], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertNull(ReviewTrends::counting([$judged])->seriousActFindings());
    }

    public function testARoundWithAnUnknownSeverityOnItsActListIsLeftOutOfTheSeriousCount(): void
    {
        $known = $this->judgedWith([$this->act('F1', 'P1'), $this->act('F2', 'P2', ['F5'])]);
        $partly = $this->round('r-2', 'alpha')->judgedWith([$this->act('F3', 'P1'), new RoundFinding('F4', null, null)->calledBy(JudgeCall::Act)], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertSame(2, ReviewTrends::counting([$known, $partly])->seriousActFindings());
    }

    public function testAnActListMadeOnlyOfMarksGivesNoDataOnSeriousFindingsBesideACountedRound(): void
    {
        $marks = $this->round('r-2', 'alpha')->judgedWith([new RoundFinding('F2', null, null)->calledBy(JudgeCall::Act)], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertSame(1, ReviewTrends::counting([$this->judgedWith([$this->act('F1', 'P2')]), $marks])->seriousActFindings());
    }

    public function testAnActEntryWhoseFindingsCameFromTwoReviewersIsNotCaughtByOneReviewerOnly(): void
    {
        $round = $this->judgedWith([$this->act('F1', 'P2', ['F2']), $this->act('F3', 'P2')])->withFindingsByReviewer(['x' => ['F1', 'F3'], 'y' => ['F2']]);

        self::assertSame(50, ReviewTrends::counting([$round])->caughtByOneReviewerOnlyPercent());
    }

    public function testAnActEntryMergingTwoFindingsOfOneReviewerIsCaughtByOneReviewerOnly(): void
    {
        $round = $this->judgedWith([$this->act('F1', 'P2', ['F2'])])->withFindingsByReviewer(['x' => ['F1', 'F2'], 'y' => []]);

        self::assertSame(100, ReviewTrends::counting([$round])->caughtByOneReviewerOnlyPercent());
    }

    public function testTwoReviewersReportingOneTrackedFindingAreTwoReviewersOfItsEntry(): void
    {
        $round = $this->judgedWith([$this->act('F1', 'P2')])->withFindingsByReviewer(['x' => ['F1'], 'y' => ['F1']]);

        self::assertSame(0, ReviewTrends::counting([$round])->caughtByOneReviewerOnlyPercent());
    }

    public function testTheOneReviewerShareIsRoundedToAWholePercent(): void
    {
        $round = $this->judgedWith([$this->act('F1', 'P2'), $this->act('F2', 'P2'), $this->act('F3', 'P2')])->withFindingsByReviewer(['x' => ['F1', 'F2', 'F3'], 'y' => ['F2', 'F3']]);

        self::assertSame(33, ReviewTrends::counting([$round])->caughtByOneReviewerOnlyPercent());
    }

    public function testRoundsWithoutARecordOfWhoReportedWhatAreLeftOutOfTheOneReviewerShare(): void
    {
        $recorded = $this->judgedWith([$this->act('F1', 'P2')])->withFindingsByReviewer(['x' => ['F1'], 'y' => []]);
        $unrecorded = $this->round('r-2', 'alpha')->judgedWith([$this->act('F1', 'P2', ['F2'])], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertSame(100, ReviewTrends::counting([$recorded, $unrecorded])->caughtByOneReviewerOnlyPercent());
    }

    public function testTheOneReviewerShareHasNoDataWhileNoJudgedRoundRecordsWhoReportedWhat(): void
    {
        $unrecorded = $this->judgedWith([$this->act('F1', 'P2', ['F2'])]);
        $unjudged = $this->round('r-2', 'alpha')->withFindingsByReviewer(['x' => ['F1']]);

        self::assertNull(ReviewTrends::counting([$unrecorded, $unjudged])->caughtByOneReviewerOnlyPercent());
    }

    public function testTheOneReviewerShareHasNoDataWhenTheRecordedRoundsHaveNoActEntry(): void
    {
        $round = $this->judgedWith([new RoundFinding('F1', 'P3', 'Deferred.')->calledBy(JudgeCall::FollowUp)])->withFindingsByReviewer(['x' => ['F1']]);

        self::assertNull(ReviewTrends::counting([$round])->caughtByOneReviewerOnlyPercent());
    }

    public function testAnActEntryNoRecordedReviewerReportedIsLeftOutOfTheOneReviewerShare(): void
    {
        $round = $this->judgedWith([$this->act('F1', 'P2'), new RoundFinding('F9', null, null)->calledBy(JudgeCall::Act)])->withFindingsByReviewer(['x' => ['F1']]);

        self::assertSame(100, ReviewTrends::counting([$round])->caughtByOneReviewerOnlyPercent());
    }

    public function testTheMedianTimeRunsFromRoundStartToTheVerdictAndSkipsUnjudgedRounds(): void
    {
        $trends = ReviewTrends::counting([$this->judgedAfter('r-1', 60), $this->judgedAfter('r-2', 252), $this->judgedAfter('r-3', 4000), $this->round('r-4', 'alpha')]);

        self::assertSame(252, $trends->medianSecondsToVerdict());
        self::assertSame('4 min 12 s', $trends->medianTimeToVerdict());
    }

    public function testTheMedianOfAnEvenCountIsTheMeanOfTheMiddleTwo(): void
    {
        $trends = ReviewTrends::counting([$this->judgedAfter('r-1', 30), $this->judgedAfter('r-2', 40)]);

        self::assertSame('35 s', $trends->medianTimeToVerdict());
    }

    public function testAMedianOfAnHourOrMoreReadsInHoursAndMinutes(): void
    {
        self::assertSame('1 h 5 min', ReviewTrends::counting([$this->judgedAfter('r-1', 3930)])->medianTimeToVerdict());
    }

    public function testTheCostPerRoundIsTheMeanOfTheRoundsWithAKnownEstimate(): void
    {
        $trends = ReviewTrends::counting([$this->costing('r-1', Cost::inUsd(0.01, '2026-10-01')), $this->costing('r-2', Cost::inUsd(0.03, '2026-10-01')), $this->costing('r-3', Cost::unknown('model-x')), $this->round('r-4', 'alpha')]);

        self::assertSame('0.0200 USD', $trends->costPerRound()?->reading());
        self::assertSame('prices of 2026-10-01', $trends->costPerRound()->priceDateReading());
    }

    public function testTheCostPerRoundHasNoDataWhenNoRoundHasAKnownEstimate(): void
    {
        self::assertNull(ReviewTrends::counting([$this->costing('r-1', Cost::unknown('model-x')), $this->round('r-2', 'alpha')])->costPerRound());
    }

    public function testTrendsSayTheyIncludeTheDemoWhenAReplayedRoundIsAmongThem(): void
    {
        $trends = ReviewTrends::counting([$this->round('r-1', 'alpha'), $this->replayed('r-2')]);

        self::assertTrue($trends->includesTheDemo());
        self::assertTrue($trends->canLeaveOutTheDemo());
    }

    public function testTrendsWithoutAReplayedRoundOfferNoDemoChip(): void
    {
        $trends = ReviewTrends::counting([$this->round('r-1', 'alpha')->markedAs(ReplayMark::Live)]);

        self::assertFalse($trends->includesTheDemo());
        self::assertFalse($trends->canLeaveOutTheDemo());
    }

    public function testLeavingOutTheDemoCountsOnlyLiveRoundsAndStillOffersTheDemoBack(): void
    {
        $trends = ReviewTrends::leavingOutTheDemo([$this->round('r-1', 'alpha'), $this->replayed('r-2')]);

        self::assertSame(1, $trends->roundsRun());
        self::assertFalse($trends->includesTheDemo());
        self::assertTrue($trends->canLeaveOutTheDemo());
        self::assertTrue($trends->demoLeftOut);
    }

    public function testLeavingOutTheDemoTakesItsRoundsOutOfBothCharts(): void
    {
        $replayed = $this->replayed('r-2')->withOutcomes([new ReviewerOutcome('r-2-security', 'security', 'standard', new DateTimeImmutable('2026-10-28T11:00:00+00:00'))])
            ->judgedWith([$this->act('F1', 'P1')], new DateTimeImmutable('2026-10-28T11:10:00+00:00'))
            ->judgedOnItsOwnMarks()
            ->withSeveritiesByReviewer(['r-2-security' => ['F1' => 'P1']])
            ->withFindingsByReviewer(['r-2-security' => ['F1']]);

        $counted = ReviewTrends::counting([$replayed]);
        $leftOut = ReviewTrends::leavingOutTheDemo([$replayed]);

        self::assertSame([1, 1], [$counted->judgeOutcomes()?->kept, $counted->reviewerCatches()?->reviewers[0]->found(Severity::P1)]);
        self::assertSame([null, null], [$leftOut->judgeOutcomes(), $leftOut->reviewerCatches()]);
    }

    public function testLeavingOutAnOnlyDemoReviewStillCountsAsAReviewHavingRun(): void
    {
        $trends = ReviewTrends::leavingOutTheDemo([$this->replayed('r-1')]);

        self::assertTrue($trends->anyReview);
        self::assertSame(0, $trends->roundsRun());
    }

    private function round(string $round, string $project): ReviewedRound
    {
        return new ReviewedRound(new RoundStart($round, $project, 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function replayed(string $round): ReviewedRound
    {
        return $this->round($round, 'demo-replay')->markedAs(ReplayMark::Replay);
    }

    private function judgedAfter(string $round, int $seconds): ReviewedRound
    {
        return $this->round($round, 'alpha')->judgedWith([], new DateTimeImmutable('2026-10-28T11:00:00+00:00')->modify(sprintf('+%d seconds', $seconds)));
    }

    private function costing(string $round, Cost $cost): ReviewedRound
    {
        return $this->round($round, 'alpha')->withTokens(new TokenFigures(100, 0, 0, 0, 20))->withCost($cost);
    }

    /**
     * @param list<string> $merged
     */
    private function act(string $id, string $severity, array $merged = []): RoundFinding
    {
        return new RoundFinding($id, $severity, 'Text of '.$id.'.', $merged)->calledBy(JudgeCall::Act);
    }

    /**
     * @param list<RoundFinding> $findings
     */
    private function judgedWith(array $findings): ReviewedRound
    {
        return $this->round('r-1', 'alpha')->judgedWith($findings, new DateTimeImmutable('2026-10-28T11:10:00+00:00'));
    }
}
