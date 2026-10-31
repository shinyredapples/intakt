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
        $exception = ReviewerStillHeld::until($this->at('12:00:01.000000'), $this->at('12:00:00.600000'));

        self::assertSame(1000, $exception->getRetryDelay());
    }

    public function testAHoldWithAFractionOfASecondOverWholeSecondsIsRoundedUp(): void
    {
        $exception = ReviewerStillHeld::until($this->at('12:00:02.500000'), $this->at('12:00:00.200000'));

        self::assertSame(3000, $exception->getRetryDelay());
    }

    public function testAHoldWithAnExactNumberOfSecondsLeftKeepsThatDelay(): void
    {
        $exception = ReviewerStillHeld::until($this->at('12:45:00.250000'), $this->at('12:00:00.250000'));

        self::assertSame(45 * 60 * 1000, $exception->getRetryDelay());
    }

    public function testAHoldThatHasAlreadyEndedGivesNoRetryDelay(): void
    {
        $exception = ReviewerStillHeld::until($this->at('12:00:00.000000'), $this->at('12:00:05.000000'));

        self::assertSame(0, $exception->getRetryDelay());
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-31T'.$time.'+00:00');
    }
}
