<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\Timeline;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Review::class)]
#[CoversClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(Cost::class)]
#[UsesClass(RoundEnding::class)]
final class ReviewTest extends TestCase
{
    public function testTheActiveRoundIsTheLatestRoundWhileItIsUnjudged(): void
    {
        $running = $this->round('round-2', 2);

        $review = new Review([$this->judged('round-1', 1), $running]);

        self::assertSame($running, $review->activeRound());
    }

    public function testAReviewWhoseRoundsAreAllReplaysIsAReplayThroughout(): void
    {
        $review = new Review([$this->round('round-1', 1)->markedAs(ReplayMark::Replay), $this->round('round-2', 2)->markedAs(ReplayMark::Replay)]);

        self::assertTrue($review->everyRoundIsAReplay());
    }

    public function testAReviewWithOneLiveRoundIsNotAReplayThroughout(): void
    {
        $review = new Review([$this->round('round-1', 1)->markedAs(ReplayMark::Live), $this->round('round-2', 2)->markedAs(ReplayMark::Replay)]);

        self::assertSame([false, true], [$review->everyRoundIsAReplay(), $review->latest()->isReplay()]);
    }

    public function testARoundReadWithoutItsReplayMarkIsRefusedAMark(): void
    {
        $this->expectExceptionObject(new LogicException('Round round-1 was read without its replay mark.'));

        $this->round('round-1', 1)->replayMark();
    }

    public function testAReviewWhoseLatestRoundIsJudgedHasNoActiveRound(): void
    {
        $review = new Review([$this->judged('round-1', 1)]);

        self::assertNull($review->activeRound());
    }

    public function testTheLastJudgedRoundIsTheNewestJudgedOne(): void
    {
        $second = $this->judged('round-2', 2);

        $review = new Review([$this->judged('round-1', 1), $second, $this->round('round-3', 3)]);

        self::assertSame($second, $review->lastJudgedRound());
    }

    public function testAReviewWithNoJudgedRoundHasNoLastJudgedRound(): void
    {
        self::assertNull(new Review([$this->round('round-1', 1)])->lastJudgedRound());
    }

    public function testOnlyTheFindingsTheJudgeCalledFixNowAreOpen(): void
    {
        $round = $this->round('round-1', 1)->judgedWith([
            new RoundFinding('F1', 'P1', 'Fix it.')->calledBy(JudgeCall::Act),
            new RoundFinding('F2', 'P3', 'Later.')->calledBy(JudgeCall::FollowUp),
            new RoundFinding('F3', 'P3', 'No.'),
        ], new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        $review = new Review([$round, $this->round('round-2', 2)]);

        self::assertSame(['F1'], array_map(static fn (RoundFinding $finding): string => $finding->id, $review->openFindings()));
    }

    public function testAReviewWithNoJudgedRoundHasNoOpenFindings(): void
    {
        self::assertSame([], new Review([$this->round('round-1', 1)])->openFindings());
    }

    public function testARoundThatEndedWithoutAJudgeCallIsNotTheActiveRound(): void
    {
        $ended = $this->round('round-1', 1)->endedAs(new RoundEnding(RoundEnds::NoOutcomeInTime, 'no outcome in time'));

        self::assertNull(new Review([$ended])->activeRound());
    }

    public function testARoundEndedAsBlockedKeepsItsEndAndReason(): void
    {
        $ended = $this->round('round-1', 1)->endedAs(new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed'));

        self::assertTrue($ended->hasEnded());
        self::assertSame('the judging failed', $ended->ending?->reason);
        self::assertFalse($ended->isJudged());
    }

    public function testARoundWithoutAnEndIsStillTheActiveRound(): void
    {
        $round = $this->round('round-1', 1);

        self::assertFalse($round->hasEnded());
        self::assertSame($round, new Review([$round])->activeRound());
    }

    public function testTheFirstRoundOfRoundsWithoutANumberIsTheSameWhicheverRoundCameFirst(): void
    {
        $first = $this->round('round-a', null);
        $second = $this->round('round-b', null);

        self::assertSame('round-a', new Review([$second, $first])->firstRound());
        self::assertSame('round-a', new Review([$first, $second])->firstRound());
    }

    public function testTheFirstRoundIsTheLowestNumberedOneWhateverItsId(): void
    {
        self::assertSame('round-z', new Review([$this->round('round-a', 2), $this->round('round-z', 1)])->firstRound());
    }

    public function testAReviewOffersTheRoundAfterItsLatest(): void
    {
        self::assertSame(3, new Review([$this->round('round-a', 1), $this->round('round-b', 2)])->nextNumber());
    }

    public function testAHistoryOnlyReviewOffersNoNextRound(): void
    {
        self::assertNull(new Review([$this->round('round-a', 1), $this->round('round-b', 2)], Timeline::HistoryOnly)->nextNumber());
    }

    private function round(string $id, ?int $number): ReviewedRound
    {
        return new ReviewedRound(new RoundStart($id, 'alpha', 2), new RoundPlace('feat/thing', $number), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function judged(string $id, int $number): ReviewedRound
    {
        return $this->round($id, $number)->judgedWith([], new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
