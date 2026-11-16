<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\FirstReviewStep;
use Intakt\Core\Dashboard\Domain\MascotPose;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\Decision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FirstReviewStep::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
final class FirstReviewStepTest extends TestCase
{
    public function testTheFirstRunShowsTheConductingOctopusBeforeAnyReview(): void
    {
        self::assertSame(MascotPose::Conducting, new FirstReviewStep(null, true)->mascot());
    }

    public function testTheFirstRunShowsTheConductingOctopusWhileTheFirstReviewIsInProgress(): void
    {
        self::assertSame(MascotPose::Conducting, new FirstReviewStep(new Review([$this->round()]), true)->mascot());
    }

    public function testTheFirstRunShowsTheConductingOctopusWhenTheFirstReviewWasSentBack(): void
    {
        foreach ([Decision::Changes, Decision::Blocked] as $decision) {
            self::assertSame(MascotPose::Conducting, new FirstReviewStep(new Review([$this->round()->decidedAs($decision)]), true)->mascot());
        }
    }

    public function testTheFirstRunShowsTheBowingOctopusOnceTheFirstReviewIsApproved(): void
    {
        self::assertSame(MascotPose::Bowing, new FirstReviewStep(new Review([$this->round()->decidedAs(Decision::Approve)]), true)->mascot());
    }

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('round-1', 'alpha', 2), new RoundPlace('feat/thing', 1), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }
}
