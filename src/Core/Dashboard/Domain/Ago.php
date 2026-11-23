<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One wording of how long ago something happened, so a review row and an account's reading read the same.
final readonly class Ago
{
    private const int MINUTE = 60;
    private const int HOUR = 3600;
    private const int DAY = 86400;

    // Whole units, rounded down, so a moment never reads as older than it is; a clock behind it reads as just now.
    public static function inWords(int $seconds): string
    {
        return match (true) {
            $seconds < self::MINUTE => 'just now',
            $seconds < self::HOUR => intdiv($seconds, self::MINUTE).' min ago',
            $seconds < self::DAY => intdiv($seconds, self::HOUR).' h ago',
            default => intdiv($seconds, self::DAY).' d ago',
        };
    }
}
