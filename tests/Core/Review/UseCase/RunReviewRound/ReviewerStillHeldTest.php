<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use DateTimeImmutable;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerStillHeld;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerStillHeld::class)]
final class ReviewerStillHeldTest extends TestCase
{
    public function testAHoldThatEndsInLessThanASecondGivesARetryDelayOfOneSecond(): void
    {
        $exception = ReviewerStillHeld::until(new DateTimeImmutable('2026-10-31T12:00:01.000000+00:00'), new DateTimeImmutable('2026-10-31T12:00:00.600000+00:00'));

        self::assertSame(1000, $exception->getRetryDelay());
    }

    public function testAHoldWithAFractionOfASecondOverWholeSecondsIsRoundedUp(): void
    {
        $exception = ReviewerStillHeld::until(new DateTimeImmutable('2026-10-31T12:00:02.500000+00:00'), new DateTimeImmutable('2026-10-31T12:00:00.200000+00:00'));

        self::assertSame(3000, $exception->getRetryDelay());
    }

    public function testAHoldWithAnExactNumberOfSecondsLeftKeepsThatDelay(): void
    {
        $exception = ReviewerStillHeld::until(new DateTimeImmutable('2026-10-31T12:45:00.250000+00:00'), new DateTimeImmutable('2026-10-31T12:00:00.250000+00:00'));

        self::assertSame(45 * 60 * 1000, $exception->getRetryDelay());
    }

    public function testAHoldThatHasAlreadyEndedGivesNoRetryDelay(): void
    {
        $exception = ReviewerStillHeld::until(new DateTimeImmutable('2026-10-31T12:00:00.000000+00:00'), new DateTimeImmutable('2026-10-31T12:00:05.000000+00:00'));

        self::assertSame(0, $exception->getRetryDelay());
    }
}
