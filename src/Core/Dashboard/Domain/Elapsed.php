<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

// Whole units, rounded down, because a wait or a running review must never read as older than it is; a clock behind the start reads as less than a minute.
final readonly class Elapsed
{
    private const int MINUTE = 60;
    private const int HOUR = 3600;
    private const int DAY = 86400;

    public function __construct(
        private DateTimeImmutable $since,
        private DateTimeImmutable $now,
    ) {
    }

    public function inWords(): string
    {
        $seconds = max(0, $this->now->getTimestamp() - $this->since->getTimestamp());

        return match (true) {
            $seconds < self::MINUTE => 'less than a minute',
            $seconds < self::HOUR => intdiv($seconds, self::MINUTE).' min',
            $seconds < self::DAY => intdiv($seconds, self::HOUR).' h '.intdiv($seconds % self::HOUR, self::MINUTE).' min',
            default => intdiv($seconds, self::DAY).' d '.intdiv($seconds % self::DAY, self::HOUR).' h',
        };
    }
}
