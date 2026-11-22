<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\PathStep;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewPath;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\StepRole;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewPath::class)]
#[CoversClass(PathStep::class)]
#[CoversClass(StepRole::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundEnding::class)]
final class ReviewPathTest extends TestCase
{
    public function testPassOneInReviewIsTheFirstOfTwoSteps(): void
    {
        self::assertSame(['Pass 1 now code_reviewer', 'Merge next'], $this->pathOf($this->round(1)));
    }

    public function testPassTwoInReviewShowsTheFirstPassAndItsFixAsDone(): void
    {
        self::assertSame(['Pass 1 done', 'Fix done', 'Pass 2 now code_reviewer', 'Merge next'], $this->pathOf($this->judged(1, Decision::Changes), $this->round(2)));
    }

    public function testPassTwoAfterAFailedPassOneShowsNoFixBetweenThePasses(): void
    {
        $failed = $this->round(1)->endedAs(new RoundEnding(RoundEnds::JudgingFailed, 'The judge gave no call.'));

        self::assertSame(['Pass 1 done', 'Pass 2 now code_reviewer', 'Merge next'], $this->pathOf($failed, $this->round(2)));
    }

    public function testAnEarlierPassWithNoRecordedOutcomeIsDoneWithoutAFix(): void
    {
        self::assertSame(['Pass 1 done', 'Pass 2 now code_reviewer', 'Merge next'], $this->pathOf($this->round(1), $this->round(2)));
    }

    public function testAnEarlierBlockedPassIsDoneWithoutAFix(): void
    {
        self::assertSame(['Pass 1 done', 'Pass 2 now code_reviewer', 'Merge next'], $this->pathOf($this->judged(1, Decision::Blocked), $this->round(2)));
    }

    public function testChangesNeededOnPassOneMakeTheFixCurrent(): void
    {
        self::assertSame(['Pass 1 done', 'Fix now developer', 'Pass 2 next', 'Merge next'], $this->pathOf($this->judged(1, Decision::Changes)));
    }

    public function testApprovalOnPassOneMakesTheMergeCurrent(): void
    {
        self::assertSame(['Pass 1 done', 'Merge now maintainer'], $this->pathOf($this->judged(1, Decision::Approve)));
    }

    public function testApprovalOnPassTwoKeepsTheWholeHistory(): void
    {
        self::assertSame(['Pass 1 done', 'Fix done', 'Pass 2 done', 'Merge now maintainer'], $this->pathOf($this->judged(1, Decision::Changes), $this->judged(2, Decision::Approve)));
    }

    public function testABlockedPassShowsNoPath(): void
    {
        self::assertSame([], $this->pathOf($this->judged(1, Decision::Blocked)));
    }

    public function testAFailedPassShowsNoPath(): void
    {
        self::assertSame([], $this->pathOf($this->round(2)->endedAs(new RoundEnding(RoundEnds::JudgingFailed, 'The judge gave no call.'))));
    }

    public function testARoundWithoutANumberIsAPassWithoutHistory(): void
    {
        self::assertSame(['Pass now code_reviewer', 'Merge next'], $this->pathOf($this->round(null)));
    }

    public function testTheRoleOnTheCurrentStepIsNamedForItsAltText(): void
    {
        self::assertSame(['Code reviewer (meerkat)', 'Developer (beaver)', 'Maintainer (tortoise)'], array_map(static fn (StepRole $role): string => $role->label(), StepRole::cases()));
    }

    /**
     * @return list<string>
     */
    private function pathOf(ReviewedRound $first, ReviewedRound ...$later): array
    {
        return array_map(
            static fn (PathStep $step): string => trim($step->label.' '.$step->state->value.' '.$step->who?->value),
            new Review([$first, ...array_values($later)])->path(),
        );
    }

    private function judged(int $number, Decision $decision): ReviewedRound
    {
        return $this->round($number)->judgedWith([], new DateTimeImmutable('2026-11-22T12:00:00+00:00'))->decidedAs($decision);
    }

    private function round(?int $number): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('round-'.($number ?? 0), 'alpha', 2), new RoundPlace('feat/thing', $number), new DateTimeImmutable('2026-11-22T11:00:00+00:00'), null);
    }
}
