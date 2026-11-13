<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;

// Vendor-neutral: a vendor's window is told by its stated length or by its reset (see told()), never by the name the vendor gives it.
enum UsageWindow: string
{
    case FiveHour = 'five-hour';
    case Weekly = 'weekly';

    public function seconds(): int
    {
        return match ($this) {
            self::FiveHour => 5 * 3600,
            self::Weekly => 7 * 86400,
        };
    }

    // A vendor that has one window reports it under a name like "primary" whatever its length, so names say nothing:
    // the stated length decides, else a reset more than five hours and at most a week ahead is the weekly window.
    public static function told(?int $windowMinutes, DateTimeImmutable $resetsAt, DateTimeImmutable $now): ?self
    {
        if (null !== $windowMinutes) {
            return array_find(self::cases(), static fn (self $window): bool => $window->seconds() === $windowMinutes * 60);
        }

        $secondsToReset = $resetsAt->getTimestamp() - $now->getTimestamp();

        return match (true) {
            $secondsToReset <= 0, $secondsToReset > self::Weekly->seconds() => null,
            $secondsToReset > self::FiveHour->seconds() => self::Weekly,
            default => self::FiveHour,
        };
    }

    // A usage-limit answer names no window, so its reset is checked against the longest one.
    public static function longest(): self
    {
        $windows = self::cases();
        usort($windows, static fn (self $one, self $other): int => $one->seconds() <=> $other->seconds());

        return array_last($windows);
    }

    // Refused because a reset in the past or further ahead than the window is long can only come from a frozen or wrong answer.
    public function refusalOfReset(DateTimeImmutable $resetsAt, DateTimeImmutable $now): ?ReadingOutcome
    {
        $secondsToReset = $resetsAt->getTimestamp() - $now->getTimestamp();

        return match (true) {
            $secondsToReset <= 0 => ReadingOutcome::ResetAlreadyPassed,
            $secondsToReset > $this->seconds() => ReadingOutcome::ResetBeyondWindow,
            default => null,
        };
    }
}
