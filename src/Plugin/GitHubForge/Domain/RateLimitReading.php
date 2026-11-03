<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use DateTimeImmutable;

final readonly class RateLimitReading
{
    public function __construct(
        public int $remaining,
        public DateTimeImmutable $resetsAt,
    ) {
    }
}
