<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

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
}
