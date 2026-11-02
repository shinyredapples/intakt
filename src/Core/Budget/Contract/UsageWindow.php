<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;

// Vendor-neutral, so a vendor plugin maps its own names here (Claude's five_hour and seven_day, Codex's primary and secondary).
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

    // A usage-limit answer names no window, so its reset is checked against the longest one.
    public static function longest(): self
    {
        $windows = self::cases();
        usort($windows, static fn (self $one, self $other): int => $one->seconds() <=> $other->seconds());

        return array_last($windows);
    }

    // A reset in the past or further ahead than the window is long is a frozen or wrong answer from the vendor.
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
