<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
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
final class ReviewedRoundTest extends TestCase
{
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

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r-1', 'alpha', 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function judged(): ReviewedRound
    {
        return $this->round()->judgedWith([], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));
    }
}
