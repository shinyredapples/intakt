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
        self::assertNull(UsageWindow::FiveHour->refusalOfReset($this->at('13:00'), $this->at('08:00')));
    }

    public function testAResetAtTheMomentOfTheAnswerHasAlreadyPassed(): void
    {
        self::assertSame(ReadingOutcome::ResetAlreadyPassed, UsageWindow::FiveHour->refusalOfReset($this->at('08:00'), $this->at('08:00')));
    }

    public function testAResetOneSecondBeyondTheWindowIsRefused(): void
    {
        self::assertSame(ReadingOutcome::ResetBeyondWindow, UsageWindow::FiveHour->refusalOfReset($this->at('13:00:01'), $this->at('08:00')));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-02T'.$time.'Z');
    }
}
