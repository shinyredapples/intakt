<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use DateTimeImmutable;

final readonly class RateLimitReading
{
    public function __construct(
        public int $remaining,
        public int $limit,
        public DateTimeImmutable $resetsAt,
    ) {
    }

    // Multiplied out, so the share of a limit that is no multiple of 100 is compared without rounding.
    public function isBelowShare(int $percent): bool
    {
        return $this->remaining * 100 < $this->limit * $percent;
    }
}
