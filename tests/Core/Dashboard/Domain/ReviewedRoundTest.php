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
