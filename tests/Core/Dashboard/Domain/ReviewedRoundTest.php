<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(ChangeAddress::class)]
final class ReviewedRoundTest extends TestCase
{
    public function testAReportedSeverityIsReadPerReviewerSoTwoReviewersKeepTheirOwnForOneId(): void
    {
        $round = $this->round()->withSeveritiesByReviewer(['r-1' => ['F1' => 'P2'], 'r-2' => ['F1' => 'P1']]);

        self::assertSame(['P2', 'P1', null, null], [$round->severityReportedBy('r-1', 'F1'), $round->severityReportedBy('r-2', 'F1'), $round->severityReportedBy('r-1', 'F2'), $round->severityReportedBy('r-3', 'F1')]);
    }

    public function testEveryFindingCountsAsRecordedUntilTheRoundIsMarkedOtherwise(): void
    {
        self::assertTrue($this->round()->everyFindingRecorded);
        self::assertFalse($this->round()->withFindingsMissing()->everyFindingRecorded);
    }

    public function testWhetherTheJudgeFellBackIsUnknownUntilItsRowSays(): void
    {
        self::assertSame([null, true, false], [$this->round()->judgeFellBack, $this->round()->judgedByFallback()->judgeFellBack, $this->round()->judgedOnItsOwnMarks()->judgeFellBack]);
    }

    public function testAJudgedRoundDecidedAsChangesGivesChangesRequested(): void
    {
        self::assertSame(ReviewOutcome::ChangesRequested, $this->judged()->decidedAs(Decision::Changes)->reviewOutcome());
    }

    public function testARoundStillInReviewGivesNoReviewOutcome(): void
    {
        self::assertNull($this->round()->reviewOutcome());
    }

    public function testARoundWhoseJudgingFailedGivesFailed(): void
    {
        $ended = $this->round()->endedAs(new RoundEnding(RoundEnds::JudgingFailed, 'The judge gave no call.'));

        self::assertSame(ReviewOutcome::Failed, $ended->reviewOutcome());
    }

    public function testTheRoundAndItsSummaryGiveTheSameOutcome(): void
    {
        $round = $this->judged()->decidedAs(Decision::Approve);

        self::assertSame(ReviewOutcome::of($round), $round->reviewOutcome());
    }

    public function testARoundWhoseReviewersAllFailedIsFailedWhileItsQuorumIsBlocked(): void
    {
        $since = new DateTimeImmutable('2026-10-28T11:01:00+00:00');
        $round = $this->round()
            ->withOutcomes([
                new ReviewerOutcome('a', 'correctness', 'standard', $since)->failed('The provider timed out.'),
                new ReviewerOutcome('b', 'docs', 'standard', $since)->failed('The provider timed out.'),
            ])
            ->judgedWith([], new DateTimeImmutable('2026-10-28T11:10:00+00:00'))
            ->decidedAs(Decision::Blocked);

        self::assertSame([ReviewOutcome::Failed, ReviewOutcome::Blocked], [$round->reviewOutcome(), $round->quorumOutcome()]);
    }

    public function testANewlyBuiltRoundHasNoReplayMarkUntilItIsMarked(): void
    {
        $round = $this->round();

        self::assertFalse($round->isReplay());
        $this->expectException(LogicException::class);
        $round->replayMark();
    }

    public function testARoundMarkedAsAReplayReadsAsAReplay(): void
    {
        $marked = $this->round()->markedAs(ReplayMark::Replay);

        self::assertTrue($marked->isReplay());
        self::assertSame(ReplayMark::Replay, $marked->replayMark());
    }

    public function testRoundsOfOnePullRequestStartedUnderTwoSpellingsAreOfTheSameReview(): void
    {
        $old = $this->roundOf('r-1', 'https://github.com/Acme/Widgets/pull/7');

        self::assertTrue($old->isOfTheSameReviewAs($this->roundOf('r-2', 'https://github.com/acme/widgets/pull/7')));
    }

    public function testRoundsOfTwoBranchesWhoseNamesDifferOnlyInLetterCaseAreOfTwoReviews(): void
    {
        self::assertFalse($this->roundOf('r-1', 'feat/Widget')->isOfTheSameReviewAs($this->roundOf('r-2', 'feat/widget')));
    }

    public function testTwoReviewersWhoBothAskForChangesAreSaidToAgree(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Changes), $this->answered('b', Decision::Changes)]);

        self::assertSame('Both say changes needed', $round->agreement());
    }

    public function testThreeReviewersWhoAllApproveAreSaidToAgreeWithoutSayingBoth(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Approve), $this->answered('b', Decision::Approve), $this->answered('c', Decision::Approve)]);

        self::assertSame('All 3 say approved', $round->agreement());
    }

    public function testReviewersWhoAnswerDifferentlyAreSaidToDisagree(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Changes), $this->answered('b', Decision::Approve)]);

        self::assertSame('The reviewers disagree', $round->agreement());
    }

    public function testASoleReviewerIsNamedAsTheReviewer(): void
    {
        $round = new ReviewedRound(new RoundStart('r-1', 'alpha', 1), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);

        self::assertSame('The reviewer says approved', $round->withOutcomes([$this->answered('a', Decision::Approve)])->agreement());
    }

    public function testNoReviewerOutcomeGivesNoAgreementLine(): void
    {
        self::assertSame('', $this->round()->withOutcomes([$this->started('a')])->agreement());
    }

    public function testTwoFailedReviewersGaveNoVerdictSoThereIsNoAgreementLine(): void
    {
        self::assertSame('', $this->round()->withOutcomes([$this->started('a')->failed('timeout'), $this->started('b')->failed('timeout')])->agreement());
    }

    public function testTwoMatchingVerdictsOnAPanelOfThreeSayHowManyOfThePanel(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Changes), $this->answered('b', Decision::Changes), $this->started('c')]);

        self::assertSame('2 of 3 say changes needed', $round->agreement());
    }

    public function testOneVerdictBesideAFailedReviewerSaysOneOfTwo(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Approve), $this->started('b')->failed('timeout')]);

        self::assertSame('1 of 2 says approved', $round->agreement());
    }

    public function testABlockedReviewerIsNotReadAsDisagreeing(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Changes), $this->started('b')->blocked('no access')]);

        self::assertSame('1 of 2 says changes needed', $round->agreement());
    }

    public function testOneRecordedVerdictOnAPanelOfTwoSaysOneOfTwoWhileTheOtherHasNoRowYet(): void
    {
        $round = $this->round()->withOutcomes([$this->answered('a', Decision::Changes)]);

        self::assertSame('1 of 2 says changes needed', $round->agreement());
    }

    public function testMoreReviewerRowsThanTheStartRowNamedCountAsThePanel(): void
    {
        $round = new ReviewedRound(new RoundStart('r-1', 'alpha', 1), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);

        self::assertSame('Both say approved', $round->withOutcomes([$this->answered('a', Decision::Approve), $this->answered('b', Decision::Approve)])->agreement());
    }

    private function answered(string $id, Decision $decision): ReviewerOutcome
    {
        return $this->started($id)->answered($decision, 0, ProjectMode::Live);
    }

    private function started(string $id): ReviewerOutcome
    {
        return new ReviewerOutcome($id, 'correctness', 'standard', new DateTimeImmutable('2026-10-28T11:00:00+00:00'));
    }

    private function round(): ReviewedRound
    {
        return $this->roundOf('r-1', 'feat/thing');
    }

    private function roundOf(string $id, string $change): ReviewedRound
    {
        return new ReviewedRound(new RoundStart($id, 'alpha', 2), new RoundPlace($change, 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function judged(): ReviewedRound
    {
        return $this->round()->judgedWith([], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));
    }
}
