<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

final readonly class JourneyFailure
{
    public function __construct(
        public string $file,
        public string $journey,
        public string $step,
        public string $reason,
    ) {
    }
}
