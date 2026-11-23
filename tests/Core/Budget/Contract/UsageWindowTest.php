<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\UsageWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsageWindow::class)]
final class UsageWindowTest extends TestCase
{
    public function testTheLongestWindowIsTheWeek(): void
    {
        self::assertSame(UsageWindow::Weekly, UsageWindow::longest());
    }

    public function testAResetWithinTheWindowIsKept(): void
    {
        self::assertNull(UsageWindow::FiveHour->refusalOfReset(new DateTimeImmutable('2026-11-02T13:00:00Z'), $this->answeredAt()));
    }

    public function testAResetAtTheMomentOfTheAnswerHasAlreadyPassed(): void
    {
        self::assertSame(ReadingOutcome::ResetAlreadyPassed, UsageWindow::FiveHour->refusalOfReset($this->answeredAt(), $this->answeredAt()));
    }

    public function testAResetOneSecondBeyondTheWindowIsRefused(): void
    {
        self::assertSame(ReadingOutcome::ResetBeyondWindow, UsageWindow::FiveHour->refusalOfReset(new DateTimeImmutable('2026-11-02T13:00:01Z'), $this->answeredAt()));
    }

    public function testAWindowStatedAsFiveHoursIsTheFiveHourWindow(): void
    {
        self::assertSame(UsageWindow::FiveHour, UsageWindow::told(300, new DateTimeImmutable('2026-11-02T09:00:00Z'), $this->answeredAt()));
    }

    public function testAWindowStatedAsFiveHoursWithNoResetIsTheFiveHourWindow(): void
    {
        self::assertSame(UsageWindow::FiveHour, UsageWindow::told(300, null, $this->answeredAt()));
    }

    public function testAWindowWithNeitherAStatedLengthNorAResetIsNone(): void
    {
        self::assertNull(UsageWindow::told(null, null, $this->answeredAt()));
    }

    public function testAWindowStatedAsFiveHoursIsTheFiveHourWindowEvenWithAResetSixDaysAhead(): void
    {
        self::assertSame(UsageWindow::FiveHour, UsageWindow::told(300, $this->sixDaysAfterTheAnswer(), $this->answeredAt()));
    }

    public function testAResetSixDaysAheadOfAWindowStatedAsFiveHoursIsRefusedAsBeyondTheWindow(): void
    {
        $window = UsageWindow::told(300, $this->sixDaysAfterTheAnswer(), $this->answeredAt());

        self::assertSame(ReadingOutcome::ResetBeyondWindow, $window?->refusalOfReset($this->sixDaysAfterTheAnswer(), $this->answeredAt()));
    }

    public function testAWindowStatedAsAWeekIsTheWeeklyWindowWhateverItsReset(): void
    {
        self::assertSame(UsageWindow::Weekly, UsageWindow::told(10080, new DateTimeImmutable('2026-11-02T09:00:00Z'), $this->answeredAt()));
    }

    public function testAWindowOfAnotherStatedLengthIsNone(): void
    {
        self::assertNull(UsageWindow::told(60, new DateTimeImmutable('2026-11-02T09:00:00Z'), $this->answeredAt()));
    }

    public function testAnUnnamedWindowResettingInFiveDaysIsTheWeeklyWindow(): void
    {
        self::assertSame(UsageWindow::Weekly, UsageWindow::told(null, new DateTimeImmutable('2026-11-07T08:00:00Z'), $this->answeredAt()));
    }

    public function testAnUnnamedWindowResettingInThreeHoursIsTheFiveHourWindow(): void
    {
        self::assertSame(UsageWindow::FiveHour, UsageWindow::told(null, new DateTimeImmutable('2026-11-02T11:00:00Z'), $this->answeredAt()));
    }

    public function testAnUnnamedWindowResettingInExactlyFiveHoursIsTheFiveHourWindow(): void
    {
        self::assertSame(UsageWindow::FiveHour, UsageWindow::told(null, new DateTimeImmutable('2026-11-02T13:00:00Z'), $this->answeredAt()));
    }

    public function testAnUnnamedWindowResettingInExactlyAWeekIsTheWeeklyWindow(): void
    {
        self::assertSame(UsageWindow::Weekly, UsageWindow::told(null, new DateTimeImmutable('2026-11-09T08:00:00Z'), $this->answeredAt()));
    }

    public function testAnUnnamedWindowResettingBeyondAWeekIsNone(): void
    {
        self::assertNull(UsageWindow::told(null, new DateTimeImmutable('2026-11-09T08:00:01Z'), $this->answeredAt()));
    }

    public function testAnUnnamedWindowWhoseResetHasPassedIsNone(): void
    {
        self::assertNull(UsageWindow::told(null, $this->answeredAt(), $this->answeredAt()));
    }

    public function testAReadingWithAFiveHourAndAWeeklyWindowMapsBoth(): void
    {
        $resets = [new DateTimeImmutable('2026-11-02T12:00:00Z'), new DateTimeImmutable('2026-11-06T08:00:00Z')];

        self::assertSame(
            [UsageWindow::FiveHour, UsageWindow::Weekly],
            array_map(fn (DateTimeImmutable $reset): ?UsageWindow => UsageWindow::told(null, $reset, $this->answeredAt()), $resets),
        );
    }

    private function answeredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-02T08:00:00Z');
    }

    private function sixDaysAfterTheAnswer(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-08T08:00:00Z');
    }
}
