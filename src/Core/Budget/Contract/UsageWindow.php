<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;

// Vendor-neutral because a vendor's own names for its windows say nothing about their length: see told().
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

    // A vendor with one window reports it as "primary", so a name would mislead: the stated length decides, else the reset does.
    public static function told(?int $windowMinutes, ?DateTimeImmutable $resetsAt, DateTimeImmutable $now): ?self
    {
        if (null !== $windowMinutes) {
            return array_find(self::cases(), static fn (self $window): bool => $window->seconds() === $windowMinutes * 60);
        }

        if (null === $resetsAt) {
            return null;
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
