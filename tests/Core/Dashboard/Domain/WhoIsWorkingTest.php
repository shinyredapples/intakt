<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Closure;
use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WhoIsWorking::class)]
#[CoversClass(Lane::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class WhoIsWorkingTest extends TestCase
{
    public function testAReviewerStillRunningIsALaneWithItsPlaceAndStart(): void
    {
        $working = new WhoIsWorking([$this->review('feat/a', 2, [$this->running('r1-a')])]);

        self::assertEquals([new Lane('r1-a', 'correctness', 'standard', 'alpha', 'feat/a', 2, $this->since(), 'r2feat/a')], $working->lanes());
    }

    public function testTheStatusLineSaysQuietWhileNobodyWorks(): void
    {
        self::assertSame('Quiet · nobody working', new WhoIsWorking([])->statusLine());
    }

    public function testTheStatusLineCountsABuildInFlightWhileNoReviewerWorks(): void
    {
        self::assertSame('Running smoothly · 1 build running', new WhoIsWorking([], 1)->statusLine());
    }

    public function testTheStatusLineCountsTheReviewersAndTheBuildsInFlight(): void
    {
        $working = new WhoIsWorking([$this->review('feat/a', 1, [$this->running('r1-a')])], 2);

        self::assertSame('Running smoothly · 1 reviewer working, 2 builds running', $working->statusLine());
    }

    public function testTheStatusLineCountsOneReviewerInTheSingular(): void
    {
        $working = new WhoIsWorking([$this->review('feat/a', 1, [$this->running('r1-a')])]);

        self::assertSame('Running smoothly · 1 reviewer working', $working->statusLine());
    }

    public function testTheStatusLineCountsTheRunningReviewersOfEveryReview(): void
    {
        $working = new WhoIsWorking([$this->review('feat/a', 1, [$this->running('r1-a')]), $this->review('feat/b', 2, [$this->running('r2-b')])]);

        self::assertSame('Running smoothly · 2 reviewers working', $working->statusLine());
    }

    /**
     * @param Closure(ReviewerOutcome): ReviewerOutcome $finish
     */
    #[DataProvider('finished')]
    public function testAReviewerThatIsNoLongerRunningIsNoLane(Closure $finish): void
    {
        $working = new WhoIsWorking([$this->review('feat/a', 1, [$finish($this->running('r1-a'))])]);

        self::assertSame(0, $working->working());
    }

    /**
     * @return iterable<string, array{Closure(ReviewerOutcome): ReviewerOutcome}>
     */
    public static function finished(): iterable
    {
        yield 'answered' => [static fn (ReviewerOutcome $outcome): ReviewerOutcome => $outcome->answered(Decision::Approve, 0, ProjectMode::Live)];
        yield 'failed' => [static fn (ReviewerOutcome $outcome): ReviewerOutcome => $outcome->failed('Timed out.')];
        yield 'blocked' => [static fn (ReviewerOutcome $outcome): ReviewerOutcome => $outcome->blocked('No access.')];
        yield 'stopped' => [static fn (ReviewerOutcome $outcome): ReviewerOutcome => $outcome->stopped()];
    }

    public function testNoReviewsMeansNobodyIsWorking(): void
    {
        $working = new WhoIsWorking([]);

        self::assertSame([[], 0], [$working->lanes(), $working->working()]);
    }

    public function testReviewersOfAJudgedRoundAreNoLanes(): void
    {
        $judged = $this->round('feat/a', 1, [$this->running('r1-a')])->judgedWith([], $this->since());

        self::assertSame(0, new WhoIsWorking([new Review([$judged])])->working());
    }

    #[DataProvider('endings')]
    public function testAReviewerStillMarkedRunningInARoundThatEndedIsNoLane(RoundEnds $end): void
    {
        $ended = $this->round('feat/a', 1, [$this->running('r1-a')])->endedAs(new RoundEnding($end, 'ended'));

        self::assertSame(0, new WhoIsWorking([new Review([$ended])])->working());
    }

    /**
     * @return iterable<string, array{RoundEnds}>
     */
    public static function endings(): iterable
    {
        yield 'no outcome in time' => [RoundEnds::NoOutcomeInTime];
        yield 'judging failed' => [RoundEnds::JudgingFailed];
    }

    public function testOnlyTheLatestRoundOfAReviewCounts(): void
    {
        $first = $this->round('feat/a', 1, [$this->running('r1-a')])->judgedWith([], $this->since());
        $second = $this->round('feat/a', 2, [$this->running('r2-a')]);

        self::assertSame(['r2-a'], $this->reviewers(new WhoIsWorking([new Review([$first, $second])])));
    }

    public function testLanesKeepTheOrderOfTheReviewsAndTheirReviewers(): void
    {
        $working = new WhoIsWorking([
            $this->review('feat/a', 1, [$this->running('r1-a'), $this->running('r1-b')]),
            $this->review('feat/b', 1, [$this->running('r2-a')]),
        ]);

        self::assertSame(['r1-a', 'r1-b', 'r2-a'], $this->reviewers($working));
    }

    public function testOnlyTheRunningOnesOfARoundAreLanes(): void
    {
        $working = new WhoIsWorking([$this->review('feat/a', 1, [$this->running('r1-a')->stopped(), $this->running('r1-b')])]);

        self::assertSame(['r1-b'], $this->reviewers($working));
    }

    /**
     * @return list<string>
     */
    private function reviewers(WhoIsWorking $working): array
    {
        return array_map(static fn (Lane $lane): string => $lane->reviewer, $working->lanes());
    }

    /**
     * @param list<ReviewerOutcome> $outcomes
     */
    private function review(string $change, int $number, array $outcomes): Review
    {
        return new Review([$this->round($change, $number, $outcomes)]);
    }

    /**
     * @param list<ReviewerOutcome> $outcomes
     */
    private function round(string $change, int $number, array $outcomes): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r'.$number.$change, 'alpha', 2), new RoundPlace($change, $number), $this->since(), null)->withOutcomes($outcomes);
    }

    private function running(string $id): ReviewerOutcome
    {
        return new ReviewerOutcome($id, 'correctness', 'standard', $this->since());
    }

    private function since(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01T10:00:00+00:00');
    }
}
