<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
final class ReviewSummaryTest extends TestCase
{
    public function testANewReviewSummaryIsInProgress(): void
    {
        $summary = $this->summary();

        self::assertSame(ReviewState::InProgress, $summary->state);
    }

    public function testAJudgedReviewSummaryIsJudgedAndKeepsItsRound(): void
    {
        $judged = $this->summary()->judged();

        self::assertSame(ReviewState::Judged, $judged->state);
        self::assertSame('r1', $judged->round);
        self::assertSame('demo', $judged->project);
        self::assertSame(2, $judged->reviewers);
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $judged->startedAt);
    }

    public function testJudgingLeavesTheOriginalSummaryInProgress(): void
    {
        $summary = $this->summary();

        $summary->judged();

        self::assertSame(ReviewState::InProgress, $summary->state);
    }

    private function summary(): ReviewSummary
    {
        return new ReviewSummary(new RoundStart('r1', 'demo', 2), new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }
}
