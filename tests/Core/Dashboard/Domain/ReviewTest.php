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
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
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
final class ReviewTest extends TestCase
{
    public function testTheActiveRoundIsTheLatestRoundWhileItIsUnjudged(): void
    {
        $running = $this->round('round-2', 2);

        $review = new Review([$this->judged('round-1', 1), $running]);

        self::assertSame($running, $review->activeRound());
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

    private function judged(string $id, int $number): ReviewedRound
    {
        return $this->round($id, $number)->judgedWith([], new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }

    private function round(string $id, int $number): ReviewedRound
    {
        return new ReviewedRound(new RoundStart($id, 'alpha', 2), new RoundPlace('feat/thing', $number, null), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }
}
