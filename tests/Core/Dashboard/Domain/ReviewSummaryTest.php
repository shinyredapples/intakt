<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\QuorumPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewSummary::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundFinding::class)]
final class ReviewSummaryTest extends TestCase
{
    public function testAListedRoundKeepsItsIdProjectReviewersAndStart(): void
    {
        $summary = new ReviewSummary($this->round());

        self::assertSame(['r1', 'demo', 2], [$summary->round, $summary->project, $summary->reviewers]);
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $summary->startedAt);
    }

    public function testAListedRoundCarriesItsChangeAndRoundNumber(): void
    {
        $summary = new ReviewSummary($this->round());

        self::assertSame(['sample-change', 2], [$summary->change, $summary->number]);
    }

    public function testARoundInProgressHasNoOutcomeAndNoFindingCount(): void
    {
        $summary = new ReviewSummary($this->round());

        self::assertSame([ReviewState::InProgress, null, null], [$summary->state, $summary->outcome, $summary->findingCount]);
    }

    public function testAJudgedRoundCarriesTheOutcomeAndTheNumberOfItsFindings(): void
    {
        $judged = $this->round()->judgedWith([new RoundFinding('F1', 'P1', 'Broken.'), new RoundFinding('F2', 'P3', 'Odd.')], new DateTimeImmutable('2026-01-01T00:10:00+00:00'))->decidedAs(Decision::Changes);

        $summary = new ReviewSummary($judged);

        self::assertSame([ReviewState::Judged, Decision::Changes, 2], [$summary->state, $summary->outcome, $summary->findingCount]);
    }

    public function testAJudgedRoundWithoutFindingsCountsZero(): void
    {
        $summary = new ReviewSummary($this->round()->judgedWith([], new DateTimeImmutable('2026-01-01T00:10:00+00:00')));

        self::assertSame(0, $summary->findingCount);
    }

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r1', 'demo', 2), new RoundPlace('sample-change', 2), new DateTimeImmutable('2026-01-01T00:00:00+00:00'), QuorumPolicy::All);
    }
}
