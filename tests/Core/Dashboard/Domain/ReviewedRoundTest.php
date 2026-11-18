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
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
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

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r-1', 'alpha', 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function judged(): ReviewedRound
    {
        return $this->round()->judgedWith([], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));
    }
}
