<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class TokenUsage
{
    public function __construct(
        public DateTimeImmutable $day,
        public UsageVendor $vendor,
        public UsageAccount $account,
        public UsageLane $lane,
        public int $tokens,
        public int $reserved,
    ) {
    }
}
